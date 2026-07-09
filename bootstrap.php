<?php

use \Slim\App as Slim;
use \App\System\Session;
use \App\System\App;
use \App\System\Logger;
use \App\System\RequestProfiler;

// Set internal encoding to what should be the default
mb_internal_encoding("UTF-8");

/**
 * Config array for Slim framework
 * Define the basic constants that the application needs
 */
$config = require dirname(__FILE__) . '/conf.php';

define('APP_HTDOCS_PATH', APP_ROOT . 'htdocs/');
define('APP_TEMPLATES_PATH', APP_ROOT . 'templates/');

require APP_ROOT . 'vendor/autoload.php';

Logger::boot(APP_ROOT . 'tmp/logs/app.log');

if (($config['mode'] ?? 'production') === 'development') {
    RequestProfiler::boot(APP_ROOT . 'tmp/logs/profile.log');
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    Logger::error($message, [
        'severity' => $severity,
        'file' => $file,
        'line' => $line,
    ]);

    return false;
});

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Logger::error($error['message'], [
        'severity' => $error['type'],
        'file' => $error['file'],
        'line' => $error['line'],
        'fatal' => true,
    ]);
});

$app = new Slim(['settings' => $config]);
App::$slim = $app;

$container = $app->getContainer();

require APP_ROOT. 'i18n.php';

/**
 * Add resources to the app. These resources will be needed at any point throughout the execution.
 */
$container['session'] = function () use ($app) {
    return new Session($app);
};
$container['langManager'] = function ($container) use ($app) {
    return new \App\System\LangManager($app, $container->get('session'));
};
$app->langManager = $container['langManager'];
$container['i18n'] = function ($container) {
    return new \App\System\I18nService(
        new \App\System\SimpleTranslator($container->get('langManager'))
    );
};
$container['view'] = function ($container) {
    $cache = '../tmp/cache';

    if ($container->get("settings")["mode"] == "development") {
        $cache = false;
    }

    $view = new \Slim\Views\Twig(APP_TEMPLATES_PATH, [
        'debug' => true,
        'cache' => $cache
    ]);

    $view->addExtension(new Twig_Extensions_Extension_I18n());
    $view->addExtension(new \Slim\Views\TwigExtension(
        $container['router'],
        $container['request']->getUri()
    ));
    $view->getEnvironment()->addFunction(new \Twig_SimpleFunction('t', function ($key, $vars = []) use ($container) {
        if (!is_array($vars)) {
            $vars = [];
        }

        return $container->get('i18n')->getTranslator()->translate($key, $vars);
    }));
    if ($container->get("settings")["mode"] == "development") {
        $view->addExtension(new Twig_Extension_Debug());
    }

    return $view;
};

$container['db'] = function ($container) {
    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection($container['settings']['mysql']);

    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    // for query debugging/printing
    //$capsule::connection()->enableQueryLog();
    //p(\Illuminate\Database\Capsule\Manager::getQueryLog());exit;

    return $capsule;
};

$container['notFoundHandler'] = function ($container) {
    return function ($request, $response) use ($container) {
        Logger::warning('Route not found.', [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        ]);

        return $container->get('view')->render($response->withStatus(404), 'common/error.html.twig', [
            'page_title' => 'Sayfa Bulunamadi',
            'error_title' => 'Istenen Sayfa Bulunamadi',
            'error_message' => 'Baglanti degismis olabilir veya icerik kaldirilmis olabilir.',
            'error_code' => 404,
            'lang' => App::getLang(),
        ]);
    };
};

$container['errorHandler'] = function ($container) {
    return function ($request, $response, $exception) use ($container) {
        Logger::exception($exception, [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        ]);

        if ($container->get('settings')['mode'] === 'development') {
            throw $exception;
        }

        return $container->get('view')->render($response->withStatus(500), 'common/error.html.twig', [
            'page_title' => 'Sistem Hatasi',
            'error_title' => 'Sistem Gecici Olarak Yanit Veremiyor',
            'error_message' => 'Beklenmeyen bir hata olustu. Islem kayda alindi.',
            'error_code' => 500,
            'lang' => App::getLang(),
        ]);
    };
};

$app->add(function ($request, $response, $next) use ($app) {
    if (
        strtoupper($request->getMethod()) === 'POST' &&
        $app->getContainer()->get('session')->isLogged()
    ) {
        $path = $request->getUri()->getPath();
        $parsedBody = $request->getParsedBody();
        $csrfToken = trim((string) $request->getHeaderLine('X-CSRF-Token'));

        if ($csrfToken === '' && is_array($parsedBody)) {
            $csrfToken = trim((string) ($parsedBody['csrf_token'] ?? $parsedBody['_csrf'] ?? ''));
        }

        if (!$app->getContainer()->get('session')->validateCsrfToken($csrfToken)) {
            Logger::warning('CSRF validation failed.', [
                'path' => $path,
                'uid' => $app->getContainer()->get('session')->getUid(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            if ($request->isXhr() || \App\System\Utils::isAPIpath($path)) {
                \App\System\Utils::jsonResponse([
                    'error' => 1,
                    'code' => 'CSRF',
                    'message' => 'Gecersiz guvenlik anahtari. Sayfayi yenileyip tekrar deneyin.',
                ]);
            }

            return $app->getContainer()->get('view')->render(
                $response->withStatus(403),
                'common/error.html.twig',
                [
                    'page_title' => 'Guvenlik Hatasi',
                    'error_title' => 'Islem Reddedildi',
                    'error_message' => 'Gecersiz guvenlik anahtari. Sayfayi yenileyip tekrar deneyin.',
                    'error_code' => 403,
                    'lang' => App::getLang(),
                ]
            );
        }
    }

    return $next($request, $response);
});

/*
 * Add some global vars before rendering each template
 */
$app->add(function ($request, $response, $next) use ($app) {

    $view = App::container()->get("view");
    $view->getEnvironment()->addGlobal('isAjax', $app->isAjax);

    //$response = $next($request, $response);return $response;
    try {
        $response = $next($request, $response);
    } catch (\Exception $e)
    {
        Logger::exception($e, [
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uid' => App::session()->getUid(),
            'ajax' => (bool) $app->isAjax,
        ]);

        if ($e->getCode() == 11 && !$app->isAjax) { // Authentication failed
            App::redirect("/login");
        }
        if ($app->isAjax) {
            $response = new \stdClass();
            $response->error = $e->getCode();
            $response->message = $e->getMessage();

            \App\System\Utils::jsonResponse($response);
        } else if (App::settings()["mode"] == "development") {
            throw $e;
        } else {
            return $view->render($response->withStatus(500), 'common/error.html.twig', [
                'page_title' => 'Sistem Hatasi',
                'error_title' => 'Sistem Gecici Olarak Yanit Veremiyor',
                'error_message' => 'Beklenmeyen bir hata olustu. Islem kayda alindi.',
                'error_code' => 500,
                'lang' => App::getLang(),
            ]);
        }
    }

    return $response;
});

if (($config['mode'] ?? 'production') === 'development') {
    $app->add(function ($request, $response, $next) use ($app) {
        $path = $request->getUri()->getPath();
        $started = microtime(true);
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
        $connection = null;

        try {
            $connection = $app->getContainer()->get('db')->getConnection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        } catch (\Throwable $e) {
            $connection = null;
        }

        try {
            return $next($request, $response);
        } finally {
            $durationMs = round((microtime(true) - $started) * 1000, 2);
            $memoryDeltaKb = round((memory_get_usage(true) - $memoryBefore) / 1024, 2);
            $peakDeltaKb = round((memory_get_peak_usage(true) - $peakBefore) / 1024, 2);
            $queryCount = 0;
            $queryTimeMs = 0.0;

            if ($connection) {
                try {
                    $queryLog = $connection->getQueryLog();
                    if (is_array($queryLog)) {
                        $queryCount = count($queryLog);
                        foreach ($queryLog as $queryEntry) {
                            $queryTimeMs += (float) ($queryEntry['time'] ?? 0);
                        }
                    }
                } catch (\Throwable $e) {
                    $queryCount = 0;
                    $queryTimeMs = 0.0;
                }
            }

            RequestProfiler::log([
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => $path,
                'query' => isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '',
                'ajax' => (bool) $app->isAjax,
                'uid' => App::session()->getUid(),
                'duration_ms' => $durationMs,
                'queries' => $queryCount,
                'query_time_ms' => round($queryTimeMs, 2),
                'memory_delta_kb' => $memoryDeltaKb,
                'peak_delta_kb' => $peakDeltaKb,
            ]);
        }
    });
}
App::container()->get("db")->getConnection();

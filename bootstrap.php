<?php

use DI\Container;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use App\System\App;
use App\System\AssetManifest;
use App\System\LegacyApp;
use App\System\LegacyInvocationStrategy;
use App\System\Logger;
use App\System\RequestProfiler;
use App\System\Session;
use App\System\TwigFactory;

mb_internal_encoding('UTF-8');

$config = require __DIR__ . '/conf.php';
$config['observability'] = array_merge([
    'slow_request_ms' => max(1, (float) (getenv('SLOW_REQUEST_MS') ?: 1000)),
    'slow_query_ms' => max(1, (float) (getenv('SLOW_QUERY_MS') ?: 250)),
    'log_level' => getenv('APP_LOG_LEVEL') ?: (($config['mode'] ?? 'production') === 'development' ? 'debug' : 'info'),
    'log_max_bytes' => max(1048576, (int) (getenv('LOG_MAX_BYTES') ?: 10485760)),
    'log_max_files' => max(1, (int) (getenv('LOG_MAX_FILES') ?: 5)),
    'log_dedupe_seconds' => max(0, (int) (getenv('LOG_DEDUPE_SECONDS') ?: 60)),
], (array) ($config['observability'] ?? []));

define('APP_HTDOCS_PATH', APP_ROOT . 'htdocs/');
define('APP_TEMPLATES_PATH', APP_ROOT . 'templates/');

require APP_ROOT . 'vendor/autoload.php';

Logger::boot(APP_ROOT . 'tmp/logs/app.log', $config['observability'] ?? []);
RequestProfiler::boot(APP_ROOT . 'tmp/logs/profile.log', $config['observability'] ?? []);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    Logger::error($message, ['severity' => $severity, 'file' => $file, 'line' => $line]);
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

$container = new Container();
$container->set('settings', $config);

$slimApp = AppFactory::createFromContainer($container);
$slimApp->getRouteCollector()->setDefaultInvocationStrategy(new LegacyInvocationStrategy());
$app = new LegacyApp($slimApp, $container);
App::$slim = $app;

$container->set('router', $slimApp->getRouteCollector()->getRouteParser());

require __DIR__ . '/i18n.php';

$container->set('session', function () use ($app) {
    return new Session($app);
});
$container->set('langManager', function (ContainerInterface $container) use ($app) {
    return new \App\System\LangManager($app, $container->get('session'));
});
$container->set('i18n', function (ContainerInterface $container) {
    return new \App\System\I18nService(
        new \App\System\SimpleTranslator($container->get('langManager'))
    );
});
$container->set('view', function (ContainerInterface $container) {
    $settings = $container->get('settings');
    $cache = false;
    if (($settings['mode'] ?? 'production') !== 'development') {
        $cachePath = APP_ROOT . 'tmp/cache';
        if (!is_dir($cachePath) && !@mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
            Logger::warning('Twig cache directory could not be created.', ['path' => $cachePath]);
        }
        if (is_dir($cachePath) && is_writable($cachePath)) {
            $cache = $cachePath;
        } else {
            Logger::warning('Twig cache directory is not writable; Twig cache disabled.', ['path' => $cachePath]);
        }
    }

    $view = \Slim\Views\Twig::create(APP_TEMPLATES_PATH, ['debug' => true, 'cache' => $cache]);
    $request = $container->has('request')
        ? $container->get('request')
        : (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', 'http://localhost/');
    TwigFactory::addLegacyRoutingFunctions($view->getEnvironment(), $container->get('router'), $request->getUri());
    TwigFactory::addFunction($view->getEnvironment(), 't', function ($key, $vars = []) use ($container) {
        return $container->get('i18n')->getTranslator()->translate($key, is_array($vars) ? $vars : []);
    });
    TwigFactory::addFunction($view->getEnvironment(), 'vite_asset', function ($entry) {
        return AssetManifest::url((string) $entry);
    });
    if (($settings['mode'] ?? 'production') === 'development') {
        TwigFactory::addDebug($view);
    }

    return $view;
});
$container->set('db', function (ContainerInterface $container) {
    $capsule = new \Illuminate\Database\Capsule\Manager();
    $capsule->addConnection($container->get('settings')['mysql']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    return $capsule;
});

$slimApp->addRoutingMiddleware();

$slimApp->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($container) {
    $connection = null;
    try {
        $connection = $container->get('db')->getConnection();
    } catch (\Throwable $e) {
        Logger::warning('Request database profiler unavailable.');
    }
    \App\System\Cache::resetMetrics();
    RequestProfiler::start($request, $connection);
    $result = null;
    try {
        $result = $handler->handle($request);
    } finally {
        $uid = 0;
        try {
            $uid = (int) App::session()->getUid();
        } catch (\Throwable $e) {
        }
        $result = RequestProfiler::finish($request, $result, $uid);
    }
    return $result;
});

$slimApp->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($container) {
    $container->get('view')->getEnvironment()->addGlobal('isAjax', App::isAjax());
    return $handler->handle($request);
});

$slimApp->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app) {
    if (strtoupper($request->getMethod()) === 'POST' && $app->getContainer()->get('session')->isLogged()) {
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

            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest' || \App\System\Utils::isAPIpath($path)) {
                $response = $app->getResponseFactory()->createResponse(403)->withHeader('Content-Type', 'application/json; charset=utf-8');
                $response->getBody()->write(json_encode([
                    'error' => 1,
                    'code' => 'CSRF',
                    'message' => 'Gecersiz guvenlik anahtari. Sayfayi yenileyip tekrar deneyin.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return $response;
            }

            return $app->getContainer()->get('view')->render($app->getResponseFactory()->createResponse(403), 'common/error.html.twig', [
                'page_title' => 'Guvenlik Hatasi',
                'error_title' => 'Islem Reddedildi',
                'error_message' => 'Gecersiz guvenlik anahtari. Sayfayi yenileyip tekrar deneyin.',
                'error_code' => 403,
                'lang' => App::getLang(),
            ]);
        }
    }
    return $handler->handle($request);
});

$slimApp->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($container, $app) {
    $container->set('request', $request);
    $app->setRequest($request);
    App::setAjax($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest');
    return $handler->handle($request);
});

$slimApp->addBodyParsingMiddleware();
$errorMiddleware = $slimApp->addErrorMiddleware(($config['mode'] ?? 'production') === 'development', false, false);
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails) use ($app) {
    Logger::warning('Route not found.', ['uri' => (string) $request->getUri(), 'method' => $request->getMethod()]);
    return $app->getContainer()->get('view')->render($app->getResponseFactory()->createResponse(404), 'common/error.html.twig', [
        'page_title' => 'Sayfa Bulunamadi',
        'error_title' => 'Istenen Sayfa Bulunamadi',
        'error_message' => 'Baglanti degismis olabilir veya icerik kaldirilmis olabilir.',
        'error_code' => 404,
        'lang' => App::getLang(),
    ]);
});
$errorMiddleware->setErrorHandler(HttpMethodNotAllowedException::class, function (ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails) use ($app) {
    Logger::warning('HTTP method not allowed.', ['uri' => (string) $request->getUri(), 'method' => $request->getMethod()]);
    $response = $app->getResponseFactory()->createResponse(405);
    if (App::isAjax() || \App\System\Utils::isAPIpath($request->getUri()->getPath())) {
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode(['error' => 1, 'message' => 'Bu HTTP metodu desteklenmiyor.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }
    return $app->getContainer()->get('view')->render($response, 'common/error.html.twig', [
        'page_title' => 'Gecersiz Islem',
        'error_title' => 'Islem Desteklenmiyor',
        'error_message' => 'Bu sayfada kullanilan istek metodu desteklenmiyor.',
        'error_code' => 405,
        'lang' => App::getLang(),
    ]);
});
$errorMiddleware->setDefaultErrorHandler(function (ServerRequestInterface $request, \Throwable $exception, bool $displayErrorDetails) use ($app) {
    Logger::exception($exception, [
        'uri' => (string) $request->getUri(),
        'method' => $request->getMethod(),
        'uid' => App::session()->getUid(),
        'ajax' => App::isAjax(),
    ]);
    $response = $app->getResponseFactory()->createResponse(500);
    if (App::isAjax() || \App\System\Utils::isAPIpath($request->getUri()->getPath())) {
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode(['error' => 1, 'message' => 'Beklenmeyen bir hata olustu.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }
    return $app->getContainer()->get('view')->render($response, 'common/error.html.twig', [
        'page_title' => 'Sistem Hatasi',
        'error_title' => 'Sistem Gecici Olarak Yanit Veremiyor',
        'error_message' => 'Beklenmeyen bir hata olustu. Islem kayda alindi.',
        'error_code' => 500,
        'lang' => App::getLang(),
    ]);
});

$container->get('db')->getConnection();

<?php

namespace App\Controllers;

use \Slim\App as Slim;
use \Slim\Http\Response;
use \App\System\App;
use \App\System\Session;
use \App\System\Utils;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Tüm controller sınıflarının temel sınıfı.
 * Slim ile Twig entegrasyonunu ve AJAX/JSON desteğini sağlar.
 */
abstract class Controller
{
    /** @var Slim */
    protected $app;

    /** @var Response */
    protected $response;

    /** @var bool AJAX isteği mi? */
    protected $isAjax = false;

    public function __construct(Slim $app, Response $response)
    {
        $this->app = $app;
        $this->response = $response;

        // AJAX tespiti: X-Requested-With header veya app.isAjax bayrağı
        $container = $app->getContainer();
        $request   = $container->get('request');
        $xrw       = $request->getHeaderLine('X-Requested-With');
        $this->isAjax = strtolower($xrw) === 'xmlhttprequest' || ($app->isAjax ?? false);
    }

    /**
     * Sayfa render metodu - Twig view ile görünüm döndürür.
     * "exec" bu method üzerinden çalışır.
     */
    public function exec(string $method, ...$args): Response
    {
        return $this->$method(...$args);
    }

    /**
     * JSON API metodu - JSON yanıt döndürür.
     */
    public function json(string $method, ...$args)
    {
        return $this->$method(...$args);
    }

    /**
     * Twig view ile sayfa render eder.
     * Giriş yapan kullanıcı her template'e 'my' değişkeni olarak aktarılır.
     */
    protected function render(string $template, array $data = []): Response
    {
        // Giriş yapan kullanıcı bilgilerini her template'e aktar
        if (!isset($data['my'])) {
            $data['my'] = App::user();
        }

        return App::container()->get('view')->render($this->response, $template, $data);
    }

    /**
     * Başarılı JSON yanıtı döndürür.
     */
    protected function success(string $message, array $extra = []): Response
    {
        $data = array_merge(['error' => 0, 'message' => $message], $extra);
        return $this->response->withJson($data);
    }

    /**
     * Hata JSON yanıtı döndürür.
     */
    protected function error(string $message, int $code = 1): Response
    {
        return $this->response->withJson(['error' => $code, 'message' => $message]);
    }

    /**
     * POST verisi alır (JSON veya form-data).
     */
    protected function input(string $key, $default = null)
    {
        $request = $this->app->getContainer()->get('request');
        $body    = $request->getParsedBody();
        return (is_array($body) && isset($body[$key])) ? $body[$key] : $default;
    }

    /**
     * GET query param alır.
     */
    protected function query(string $key, $default = null)
    {
        $request = $this->app->getContainer()->get('request');
        $params  = $request->getQueryParams();
        return isset($params[$key]) ? $params[$key] : $default;
    }

    /**
     * Oturumdaki kullanıcı ID'sini döndürür.
     */
    protected function uid(): int
    {
        return App::session()->getUid();
    }

    /**
     * Yönlendirme yapar.
     */
    protected function redirect(string $url): Response
    {
        return $this->response->withRedirect($url);
    }
}

<?php

namespace App\System;


/**
 * Helper to access Slim container easily with some helpful methods
 *
 * Class App
 */
class App
{
    const CURRENT_VERSION = "0.3";

    /**
     * Request-level AJAX state. Keep this outside the Slim 3 application
     * object so PHP 8.2 does not create a dynamic property on the framework.
     *
     * @var bool
     */
    private static $ajax = false;

    /**
     * @var \Slim\App
     */
    public static $slim;

    /**
     * Resolves an instance of a slim object
     *
     * @param $key
     * @return mixed
     */
    public static function make($key)
    {
        return self::getInstance()->$key;
    }

    /**
     * @return null|Slim
     */
    public static function getInstance()
    {
        return self::$slim;
    }

    /**
     * Gets a config key
     *
     * @param $key
     * @return mixed
     */
    public static function config($key)
    {
        return self::getInstance()->config($key);
    }

    /**
     * @param $name
     * @param $value
     * @param null $time
     * @param null $path
     * @param null $domain
     * @param null $secure
     * @param null $httponly
     */
    public static function setCookie(
        $name,
        $value,
        $time = null,
        $path = null,
        $domain = null,
        $secure = null,
        $httponly = null
    ) {
        self::getInstance()->setCookie($name, $value, $time, $path, $domain, $secure, $httponly);
    }

    /**
     * Get route for an specified route name.
     * If you explicitly set lang it will be overided.
     *
     * @param $route
     * @param array $params
     * @return string
     */
    public static function route($route, $params = array(), $withBase = false)
    {
        $app = self::getInstance();

        $params = array_merge(array('lang' => self::getLang()), $params);

        $url = $app->urlFor($route, $params);

        if ($withBase) {
            $uri = self::container()->get('request')->getUri();
            $url = rtrim($uri->getScheme() . '://' . $uri->getAuthority(), '/') . $url;
        }

        return $url;
    }

    /**
     * Redirect user to given url
     * @param string $url
     */
    public static function redirect ($url)
    {
        header("Location: $url");
        exit;
    }

    /**
     * @return mixed
     */
    public static function getLang()
    {
        return self::container()->get('langManager')->getLocale();
    }

    public static function setAjax($value)
    {
        self::$ajax = (bool) $value;
    }

    public static function isAjax()
    {
        return self::$ajax;
    }

    public static function container () {
        return App::getInstance()->getContainer();
    }

    /**
     * @return Session
     */
    public static function session () {
        return self::container()->get("session");
    }

    /**
     * @return Session
     */
    public static function user () {
        return self::container()->get("session");
    }

    /**
     * @return array
     */
    public static function settings () {
        return self::container()->get("settings");
    }
}

<?php

namespace App\System;

/**
 * Keeps Twig integration in one place while the application moves from the
 * legacy Slim 3 view stack to current Twig releases.
 */
final class TwigFactory
{
    public static function addI18n($view): void
    {
        // The application uses its own t() function. The abandoned
        // twig/extensions package is intentionally not required anymore.
    }

    public static function addDebug($view): void
    {
        if (class_exists('Twig_Extension_Debug')) {
            $view->addExtension(new \Twig_Extension_Debug());
            return;
        }

        if (class_exists('Twig\\Extension\\DebugExtension')) {
            $view->addExtension(new \Twig\Extension\DebugExtension());
        }
    }

    public static function addFunction($environment, $name, callable $callable): void
    {
        if (class_exists('Twig_SimpleFunction')) {
            $environment->addFunction(new \Twig_SimpleFunction($name, $callable));
            return;
        }

        if (class_exists('Twig\\TwigFunction')) {
            $environment->addFunction(new \Twig\TwigFunction($name, $callable));
        }
    }

    public static function addLegacyRoutingFunctions($environment, $router, $uri): void
    {
        self::addFunction($environment, 'path_for', function ($name, $data = [], $query = []) use ($router) {
            return $router->urlFor((string) $name, is_array($data) ? $data : [], is_array($query) ? $query : []);
        });

        $baseUrl = '';
        if ($uri && method_exists($uri, 'getScheme') && method_exists($uri, 'getAuthority')) {
            $baseUrl = rtrim((string) $uri->getScheme() . '://' . (string) $uri->getAuthority(), '/');
        }

        self::addFunction($environment, 'base_url', static function () use ($baseUrl) {
            return $baseUrl;
        });

        self::addFunction($environment, 'current_path', static function ($withQuery = false) use ($uri) {
            $path = $uri && method_exists($uri, 'getPath') ? (string) $uri->getPath() : '/';
            if ($withQuery && $uri && method_exists($uri, 'getQuery') && $uri->getQuery() !== '') {
                $path .= '?' . $uri->getQuery();
            }
            return $path;
        });

        self::addFunction($environment, 'is_current_path', static function ($path) use ($uri) {
            return $uri && method_exists($uri, 'getPath') && (string) $uri->getPath() === (string) $path;
        });
    }
}

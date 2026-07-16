<?php

namespace App\System;

use DI\Container;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Small compatibility shell for the existing route/controller conventions.
 * The underlying application and container are Slim 4/PSR based.
 */
final class LegacyApp
{
    private App $app;
    private ContainerInterface $container;
    /** @var object|null Active Slim4 route group while legacy callbacks register routes. */
    private $routeTarget;

    public function __construct(App $app, ContainerInterface $container)
    {
        $this->app = $app;
        $this->container = $container;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function config(string $key = null)
    {
        $settings = $this->container->get('settings');
        return $key === null ? $settings : ($settings[$key] ?? null);
    }

    public function urlFor(string $name, array $data = [], array $queryParams = []): string
    {
        return $this->container->get('router')->pathFor($name, $data, $queryParams);
    }

    public function setRequest($request): void
    {
        if ($this->container instanceof Container) {
            $this->container->set('request', $request);
        }
    }

    public function getSlimApp(): App
    {
        return $this->app;
    }

    public function group(string $pattern, callable $callback)
    {
        $target = $this->routeTarget ?: $this->app;
        $legacy = $this;

        return $target->group($pattern, function ($group) use ($callback, $legacy) {
            $previous = $legacy->routeTarget;
            $legacy->routeTarget = $group;
            try {
                $callback($group);
            } finally {
                $legacy->routeTarget = $previous;
            }
        });
    }

    public function __call(string $method, array $arguments)
    {
        $target = $this->routeTarget ?: $this->app;
        return $target->{$method}(...$arguments);
    }
}

<?php

namespace App\System;

use Slim\Routing\RouteParser;

/**
 * Keeps the existing pathFor() calls working on Slim 4's RouteParser.
 */
final class LegacyRouter
{
    private RouteParser $parser;

    public function __construct(RouteParser $parser)
    {
        $this->parser = $parser;
    }

    public function pathFor(string $name, array $data = [], array $queryParams = []): string
    {
        return $this->parser->urlFor($name, $data, $queryParams);
    }

    public function urlFor(string $name, array $data = [], array $queryParams = []): string
    {
        return $this->pathFor($name, $data, $queryParams);
    }

    public function hasNamedRoute(string $name): bool
    {
        try {
            $this->parser->urlFor($name);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

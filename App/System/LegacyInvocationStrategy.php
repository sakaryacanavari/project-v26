<?php

namespace App\System;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\InvocationStrategyInterface;

/** Keeps legacy controller routes that write into the supplied response working on Slim4. */
final class LegacyInvocationStrategy implements InvocationStrategyInterface
{
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        $result = $callable($request, $response, $routeArguments);
        return $result instanceof ResponseInterface ? $result : $response;
    }
}

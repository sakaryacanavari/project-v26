<?php

use \App\System\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require __DIR__ . '/bootstrap.php';

$app->add(function (ServerRequestInterface $request, ResponseInterface $response, callable $next) use ($app) {

    if ($request->isXhr()) {
        $app->isAjax = true;
    } else {
        $app->isAjax = false;
    }

    return $next($request, $response);
});

require __DIR__ . '/routes.php';

$app->run();

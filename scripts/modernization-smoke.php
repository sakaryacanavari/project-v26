<?php

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/routes.php';

use App\System\App;

$app = App::getInstance();
$view = App::container()->get('view');
$rendered = $view->fetch('common/error.html.twig', [
    'lang' => 'tr',
    'page_title' => 'Smoke test',
    'error_title' => 'Smoke test',
    'error_message' => 'Bootstrap render check',
    'error_code' => 200,
]);

if (!is_object($app) || strlen((string) $rendered) < 100) {
    fwrite(STDERR, "Bootstrap or Twig render smoke test failed.\n");
    exit(1);
}

$router = App::container()->get('router');
$routeNames = ['home', 'login', 'settings', 'storage', 'gyms'];
foreach ($routeNames as $routeName) {
    try {
        $route = $router->urlFor($routeName);
    } catch (Throwable $e) {
        $route = '';
    }
    if ($route === '') {
        fwrite(STDERR, 'Missing named route: ' . $routeName . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "Bootstrap, container, Twig render and named-route smoke tests passed.\n");

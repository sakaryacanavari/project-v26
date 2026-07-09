<?php

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

if (preg_match('#^/htdocs(?:/index\.php)?(?:/(.*))?$#', $requestUri, $matches)) {
    $target = '/' . ltrim($matches[1] ?? '', '/');

    if (!empty($_SERVER['QUERY_STRING'])) {
        $target .= '?' . $_SERVER['QUERY_STRING'];
    }

    header('HTTP/1.1 302 Found');
    header('Location: ' . $target);
    exit;
}

require dirname(__DIR__) . '/index.php';

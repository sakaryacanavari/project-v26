<?php

ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

define('APP_ROOT', __DIR__ . DIRECTORY_SEPARATOR);

return [
    "mysql" => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: 'erepublik',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '2525',
        'charset'   => 'utf8',
        'collation' => 'utf8_unicode_ci',
        'prefix'    => '',
    ],
    "password_hash" => "!·$%&3456jsdehg..",
    'mode' => 'development',
    'displayErrorDetails' => true,
    'debug' => true,
    'cookies.encrypt' => false,
    'cookies.secret_key' => '45r67t4e56uyhtagdfhg-.khj',
    'cookies.cipher' => 'AES-256-CBC',
    'cookies.cipher_mode' => 'cbc',
    'cookies.path' => '/',
    'cookies.domain' => "erepublik.dev",
];

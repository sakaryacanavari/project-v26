<?php

// Şimdilik XAMPP'ta rahat çalışman için ortamı 'development' olarak zorluyoruz.
// Canlı sunucuya geçtiğinde burayı 'production' yapmalısın.
$environment = 'development';

if ($environment === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    // Orijinalindeki gibi Notice ve Deprecated uyarılarını gizledim, sadece kritik hataları göreceksin
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED); 
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . DIRECTORY_SEPARATOR);
}

return [
    "mysql" => [
    'driver'    => 'mysql',
    'host'      => '127.0.0.1',
    'port'      => '3308',
    'database'  => 'erepublik',
    'username'  => 'root',
    'password'  => '2525',
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => '',
],
    
    // Şifreleme anahtarı
    "password_hash" => getenv('APP_KEY') ?: '!·$%&3456jsdehg..',
    
    'mode'                => $environment,
    'displayErrorDetails' => ($environment === 'development'),
    'debug'               => ($environment === 'development'),
    
    // Cookie ayarları (Orijinal yapı korundu, güvenlik eklendi)
    'cookies.encrypt'     => filter_var(getenv('COOKIE_ENCRYPT'), FILTER_VALIDATE_BOOLEAN) ?: false,
    'cookies.secret_key'  => getenv('COOKIE_SECRET') ?: '45r67t4e56uyhtagdfhg-.khj',
    'cookies.cipher'      => 'AES-256-CBC',
    'cookies.cipher_mode' => 'cbc',
    'cookies.path'        => '/',
    'cookies.domain'      => getenv('COOKIE_DOMAIN') ?: 'localhost',
    
    // XSS ve aradaki adam saldırılarına karşı görünmez kalkan (Kodun yapısını bozmaz)
    'cookies.httponly'    => true, 
    'cookies.secure'      => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', 
];
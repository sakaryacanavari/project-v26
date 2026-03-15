<?php

namespace App\System;

/**
 * Genel yardımcı fonksiyonlar içeren sınıf.
 */
class Utils
{
    /**
     * JSON yanıtı çıktılar ve çalışmayı durdurur.
     */
    public static function jsonResponse($data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Başarılı JSON yanıtı döndürür.
     */
    public static function successJson(string $message, array $extra = []): void
    {
        $response = array_merge(['error' => 0, 'message' => $message], $extra);
        static::jsonResponse($response);
    }

    /**
     * Hata JSON yanıtı döndürür.
     */
    public static function errorJson(string $message, int $code = 1): void
    {
        static::jsonResponse(['error' => $code, 'message' => $message]);
    }

    /**
     * MD5 tabanlı şifre şifreleme (mevcut sistemle uyumluluk için).
     */
    public static function encryptPassword(string $password, string $salt = ''): string
    {
        $hashSalt = App::settings()['password_hash'] ?? '';
        return md5($password . $hashSalt . $salt);
    }
}

<?php

namespace App\System;

use \Slim\App as Slim;

/**
 * Oturum yönetimini sağlayan sınıf.
 * PHP session'ını yönetir ve kullanıcı giriş kontrollerini yapar.
 */
class Session
{
    /** @var Slim */
    private $app;

    public function __construct(Slim $app)
    {
        $this->app = $app;

        // Oturum başlatılmamışsa başlat
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Giriş yapılmış kullanıcının ID'sini döndürür.
     * Giriş yapılmamışsa null döner.
     */
    public function getUid(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /**
     * Kullanıcı girişini başlatır (UID'yi oturuma kaydeder).
     */
    public function setUid(int $uid): void
    {
        $_SESSION['uid'] = $uid;
    }

    /**
     * Oturumu temizleyerek kullanıcıyı çıkış yaptırır.
     */
    public function destroy(): void
    {
        session_destroy();
        $_SESSION = [];
    }

    /**
     * Giriş yapılmamışsa LoginException fırlatır.
     * Middleware olarak kullanılır.
     *
     * @throws \Exception Kod 11 ile giriş hatası
     */
    public function ensureLogged(): void
    {
        if (!$this->getUid()) {
            throw new \Exception('Giriş yapmanız gerekiyor.', 11);
        }
    }

    /**
     * Kullanıcının giriş yapıp yapmadığını kontrol eder.
     */
    public function isLogged(): bool
    {
        return (bool) $this->getUid();
    }
}

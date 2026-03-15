<?php

namespace App\System;

use \Slim\App as Slim;
use \Illuminate\Database\Capsule\Manager as DB;
use \App\Models\User as UserModel;

/**
 * Uygulama genelinde statik yardımcı metotlar sağlayan merkezi sınıf.
 * Slim container, session ve kullanıcı erişimini kolaylaştırır.
 */
class App
{
    /** @var Slim Slim uygulama örneği */
    public static $slim;

    /**
     * DI container'a erişim sağlar.
     */
    public static function container()
    {
        return static::$slim->getContainer();
    }

    /**
     * Slim ayarlarına erişim sağlar.
     */
    public static function settings()
    {
        return static::container()->get('settings');
    }

    /**
     * Oturum nesnesine erişim sağlar.
     */
    public static function session(): Session
    {
        return static::container()->get('session');
    }

    /**
     * Giriş yapmış kullanıcının veritabanı kaydını Eloquent modeli olarak döndürür.
     * Kullanıcı giriş yapmamışsa null döner.
     * isCongressist(), isAdmin() gibi metotlara erişim için Eloquent model kullanılır.
     */
    public static function user(): ?UserModel
    {
        $uid = static::session()->getUid();
        if (!$uid) {
            return null;
        }

        return UserModel::where('id', $uid)->first();
    }

    /**
     * Belirtilen URL'ye yönlendirme yapar.
     */
    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

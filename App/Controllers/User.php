<?php

namespace App\Controllers;

use \App\System\App;
use \App\System\Utils;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kullanıcı işlemlerini yöneten controller.
 * Giriş/çıkış, kayıt, profil, spor salonu ve depo işlemleri.
 */
class User extends Controller
{
    // -------------------------------------------------------
    // Sayfa görünümleri
    // -------------------------------------------------------

    /**
     * Giriş ekranını gösterir.
     */
    public function showLogin()
    {
        return $this->render('user/login.html.twig');
    }

    /**
     * Kayıt ekranını gösterir.
     */
    public function showSignup()
    {
        // Kayıt için ülke listesi
        $countries = DB::table('countries')->orderBy('name')->get();
        return $this->render('user/signup.html.twig', ['countries' => $countries]);
    }

    /**
     * Spor salonlarını gösterir.
     */
    public function showGyms()
    {
        $uid = $this->uid();
        $gymData = DB::table('user_gyms')->where('uid', $uid)->first();

        return $this->render('gym/gyms.html.twig', [
            'gyms' => $gymData
        ]);
    }

    /**
     * Depo ekranını gösterir.
     */
    public function showStorage()
    {
        $uid = $this->uid();
        $items = DB::table('user_items')->where('uid', $uid)->get();

        return $this->render('user/storage.html.twig', [
            'items' => $items
        ]);
    }

    // -------------------------------------------------------
    // API işlemleri
    // -------------------------------------------------------

    /**
     * Giriş işlemini yapar (JSON API).
     * MD5 tabanlı şifre doğrulaması kullanır.
     */
    public function doLogin()
    {
        $email = trim($this->input('email', ''));
        $password = trim($this->input('password', ''));

        if (!$email || !$password) {
            return $this->error('E-posta ve şifre gereklidir.');
        }

        $user = DB::table('users')->where('email', $email)->first();
        if (!$user) {
            return $this->error('Kullanıcı bulunamadı.');
        }

        // Mevcut MD5 tabanlı şifre doğrulaması
        $hashedPassword = Utils::encryptPassword($password);
        if ($user->password !== $hashedPassword) {
            return $this->error('Şifre hatalı.');
        }

        // Oturumu başlat
        App::session()->setUid($user->id);

        return $this->success('Giriş başarılı.');
    }

    /**
     * Yeni kullanıcı kaydı oluşturur.
     * User, UserMoney ve UserGym kayıtlarını birlikte oluşturur.
     */
    public function signup()
    {
        $nick = trim($this->input('nick', ''));
        $email = trim($this->input('email', ''));
        $password = trim($this->input('password', ''));
        $regionId = (int) $this->input('region', 0);

        // Temel doğrulama
        if (!$nick || !$email || !$password || !$regionId) {
            return $this->error('Tüm alanlar zorunludur.');
        }

        if (strlen($nick) < 3 || strlen($nick) > 30) {
            return $this->error('Kullanıcı adı 3-30 karakter arasında olmalıdır.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Geçerli bir e-posta adresi girin.');
        }

        // Çakışma kontrolü
        if (DB::table('users')->where('email', $email)->exists()) {
            return $this->error('Bu e-posta adresi zaten kullanılıyor.');
        }

        if (DB::table('users')->where('nick', $nick)->exists()) {
            return $this->error('Bu kullanıcı adı zaten alınmış.');
        }

        // Bölge kontrolü
        if (!DB::table('regions')->where('id', $regionId)->exists()) {
            return $this->error('Geçersiz bölge seçimi.');
        }

        // Şifreyi hash'le ve kullanıcıyı oluştur
        $hashedPassword = Utils::encryptPassword($password);
        $now = date('Y-m-d H:i:s');

        $uid = DB::table('users')->insertGetId([
            'nick'           => $nick,
            'email'          => $email,
            'password'       => $hashedPassword,
            'status'         => 1,
            'region'         => $regionId,
            'level'          => 1,
            'xp'             => 0,
            'strength'       => 1,
            'economic_skill' => 1,  // Başlangıç ekonomik yeteneği
            'economic_xp'    => 0,  // Başlangıç ekonomik XP
            'theme'          => 'dark_cyan', // Varsayılan tema
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // UserMoney kaydı oluştur (ülke para birimiyle başlangıç parası)
        $country = DB::table('regions')
            ->join('countries', 'regions.country', '=', 'countries.id')
            ->where('regions.id', $regionId)
            ->select('countries.currency')
            ->first();

        $moneyData = ['uid' => $uid, 'gold' => 0.00];
        if ($country) {
            $moneyData[$country->currency] = 100.00; // Başlangıç parası
        }

        DB::table('user_money')->insert($moneyData);

        // UserGym kaydı oluştur
        DB::table('user_gyms')->insert(['uid' => $uid]);

        // Otomatik giriş yap
        App::session()->setUid($uid);

        return $this->success('Kayıt başarıyla tamamlandı!');
    }

    /**
     * Çıkış yapar.
     */
    public function logout()
    {
        App::session()->destroy();
        return $this->redirect('/login');
    }
}

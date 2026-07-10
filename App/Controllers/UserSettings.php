<?php

namespace App\Controllers;

use App\System\App;
use App\System\Controller;
use App\System\DmPrivacy;
use App\System\GameExperience;
use App\System\Notify;
use Illuminate\Database\Capsule\Manager as DB;

class UserSettings extends Controller
{
    public static function getLanguageConfig()
    {
        return [
            'tr' => ['name' => 'Turkce', 'flag' => 'TR'],
            'en' => ['name' => 'English', 'flag' => 'GB'],
        ];
    }

    public static function getThemeConfig()
    {
        return [
            'modes' => [
                'dark'    => ['name' => 'Koyu', 'description' => 'Dengeli koyu gorunum', 'icon' => 'fa-solid fa-moon text-indigo-400'],
                'command' => ['name' => 'Gece Mavisi', 'description' => 'Lacivert tonlarda sakin gorunum', 'icon' => 'fa-solid fa-circle-half-stroke text-blue-300'],
                'neon'    => ['name' => 'Mor', 'description' => 'Mor/mavi modern gorunum', 'icon' => 'fa-solid fa-gem text-purple-300'],
                'war'     => ['name' => 'Kontrast', 'description' => 'Daha belirgin ve okunakli gorunum', 'icon' => 'fa-solid fa-border-all text-slate-200'],
                'archive' => ['name' => 'Göz Konforu Modu', 'description' => 'Daha sıcak tonlar ve azaltılmış parlaklıkla uzun kullanım için rahat görünüm', 'icon' => 'fa-solid fa-eye text-slate-400'],
            ],
            'colors' => [
                'purple'   => ['name' => 'Mor', 'icon' => 'fa-solid fa-vr-cardboard'],
                'blue'     => ['name' => 'Mavi', 'icon' => 'fa-solid fa-bolt'],
                'diamond'  => ['name' => 'Turkuaz', 'icon' => 'fa-solid fa-gem'],
                'amber'    => ['name' => 'Amber', 'icon' => 'fa-solid fa-building-columns'],
                'red'      => ['name' => 'Kirmizi', 'icon' => 'fa-solid fa-triangle-exclamation'],
                'matrix'   => ['name' => 'Yesil', 'icon' => 'fa-solid fa-circle-check'],
                'mono'     => ['name' => 'Gri', 'icon' => 'fa-solid fa-shield'],
                'lavender' => ['name' => 'Lavanta', 'icon' => 'fa-solid fa-star'],
            ],
            'eyeComfortLevels' => [
                'light' => ['name' => 'Hafif', 'description' => 'Hafif sicak ton, minimum parlaklik azaltimi'],
                'balanced' => ['name' => 'Dengeli', 'description' => 'Sicak ton ve azaltilmis parlaklik dengesi'],
                'intense' => ['name' => 'Yogun', 'description' => 'Daha sicak gorunum, daha belirgin mavi isik azaltimi'],
            ],
        ];
    }

    private static function getDefaultThemeState()
    {
        return [
            'mode' => 'dark',
            'color' => 'purple',
            'eye_comfort_level' => 'balanced',
            'combined' => 'dark_purple_balanced',
        ];
    }

    private static function parseThemeString($themeString = null)
    {
        $config = self::getThemeConfig();
        $default = self::getDefaultThemeState();

        $themeString = trim((string) $themeString);
        if ($themeString === '') {
            return $default;
        }

        $parts = explode('_', $themeString);
        $mode = isset($parts[0]) ? trim($parts[0]) : $default['mode'];
        $color = isset($parts[1]) ? trim($parts[1]) : $default['color'];
        $eyeComfortLevel = isset($parts[2]) ? trim($parts[2]) : $default['eye_comfort_level'];

        $legacyModes = [
            'oled' => 'command',
            'dim' => 'archive',
            'magma' => 'war',
            'aurora' => 'neon',
        ];
        $legacyColors = [
            'cyan' => 'blue',
            'pink' => 'lavender',
            'overdrive' => 'amber',
            'blood' => 'red',
        ];

        if (isset($legacyModes[$mode])) {
            $mode = $legacyModes[$mode];
        }

        if (isset($legacyColors[$color])) {
            $color = $legacyColors[$color];
        }

        if (!isset($config['modes'][$mode])) {
            $mode = $default['mode'];
        }

        if (!isset($config['colors'][$color])) {
            $color = $default['color'];
        }

        if ($mode === 'archive') {
            $color = 'amber';
        }

        if (!isset($config['eyeComfortLevels'][$eyeComfortLevel])) {
            $eyeComfortLevel = $default['eye_comfort_level'];
        }

        return [
            'mode' => $mode,
            'color' => $color,
            'eye_comfort_level' => $eyeComfortLevel,
            'combined' => $mode . '_' . $color . '_' . $eyeComfortLevel,
        ];
    }

    private static function getAuthenticatedUserThemeState()
    {
        try {
            $uid = App::user()->getUid();
            $user = DB::table('users')
                ->select('theme')
                ->where('id', $uid)
                ->first();

            if (!$user) {
                return self::getDefaultThemeState();
            }

            return self::parseThemeString(isset($user->theme) ? $user->theme : null);
        } catch (\Exception $e) {
            return self::getDefaultThemeState();
        }
    }

    private static function getAuthenticatedLanguage()
    {
        $default = 'tr';

        try {
            if (!DB::getSchemaBuilder()->hasColumn('users', 'language')) {
                return App::getLang() ?: $default;
            }

            $uid = App::user()->getUid();
            $user = DB::table('users')
                ->select('language')
                ->where('id', $uid)
                ->first();

            $language = $user && !empty($user->language) ? trim((string) $user->language) : '';
            if (isset(self::getLanguageConfig()[$language])) {
                return $language;
            }
        } catch (\Exception $e) {
        }

        return App::getLang() ?: $default;
    }

    private function hasValidCsrf()
    {
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $this->validateCsrf($token);
    }

    public function updateProfile()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = App::user()->getUid();
            $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
            $avatar = trim(strip_tags($_POST['avatar'] ?? ''));
            $bio = trim(strip_tags((string) ($_POST['bio'] ?? '')));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['error' => true, 'message' => 'Gecersiz e-posta!'];
            }

            if (function_exists('mb_substr')) {
                $bio = mb_substr($bio, 0, 280, 'UTF-8');
            } else {
                $bio = substr($bio, 0, 280);
            }

            $payload = [
                'email' => $email,
                'avatar' => $avatar,
            ];

            if (DB::getSchemaBuilder()->hasColumn('users', 'bio')) {
                $payload['bio'] = $bio;
            }

            DB::table('users')
                ->where('id', $uid)
                ->update($payload);

            return ['success' => true, 'message' => 'Profil guncellendi.'];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Kayit Hatasi.'];
        }
    }

    public function showSettings()
    {
        $config = self::getThemeConfig();
        $themeState = self::getAuthenticatedUserThemeState();
        $profileBio = '';

        try {
            if (DB::getSchemaBuilder()->hasColumn('users', 'bio')) {
                $profileBio = (string) (DB::table('users')->where('id', App::user()->getUid())->value('bio') ?? '');
            }
        } catch (\Exception $e) {
            $profileBio = '';
        }

        return $this->render('user/settings.html.twig', [
            'themeConfig' => $config,
            'themeState' => $themeState,
            'languageConfig' => self::getLanguageConfig(),
            'languageState' => self::getAuthenticatedLanguage(),
            'notificationSettings' => Notify::getPreferences((int) App::user()->getUid()),
            'dmPrivacySettings' => DmPrivacy::getPreferences((int) App::user()->getUid()),
            'dmPrivacySupport' => DmPrivacy::getSupportState(),
            'gameExperienceSettings' => GameExperience::getPreferences((int) App::user()->getUid()),
            'gameExperienceQuickActions' => GameExperience::getQuickActions(),
            'profileBio' => $profileBio,
        ]);
    }

    public function updatePassword()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = App::user()->getUid();
            $oldPass = $_POST['old_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';

            if (strlen($newPass) < 6) {
                return ['error' => true, 'message' => 'Sifre en az 6 karakter olmali!'];
            }

            $user = DB::table('users')->where('id', $uid)->first();
            if (!$user) {
                return ['error' => true, 'message' => 'Kullanici bulunamadi!'];
            }

            $isPasswordCorrect = false;

            if (!empty($user->password) && password_verify($oldPass, $user->password)) {
                $isPasswordCorrect = true;
            } elseif (!empty($user->password) && md5($oldPass) === $user->password) {
                $isPasswordCorrect = true;
            }

            if (!$isPasswordCorrect) {
                return ['error' => true, 'message' => 'Mevcut sifre yanlis!'];
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);

            DB::table('users')
                ->where('id', $uid)
                ->update(['password' => $newHash]);

            return ['success' => true, 'message' => 'Sifre degistirildi.'];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Guvenlik Hatasi.'];
        }
    }

    public function updateTheme()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = App::user()->getUid();
            $mode = trim(strip_tags($_POST['mode'] ?? 'dark'));
            $color = trim(strip_tags($_POST['color'] ?? 'purple'));
            $eyeComfortLevel = trim(strip_tags($_POST['eye_comfort_level'] ?? 'balanced'));

            $config = self::getThemeConfig();

            if (!isset($config['modes'][$mode])) {
                return ['error' => true, 'message' => 'Yetkisiz tema!'];
            }

            if (!isset($config['eyeComfortLevels'][$eyeComfortLevel])) {
                $eyeComfortLevel = 'balanced';
            }

            if ($mode === 'archive') {
                $color = 'amber';
            } elseif (!isset($config['colors'][$color])) {
                return ['error' => true, 'message' => 'Yetkisiz tema!'];
            }

            $combined = $mode . '_' . $color . '_' . $eyeComfortLevel;

            DB::table('users')
                ->where('id', $uid)
                ->update(['theme' => $combined]);

            return [
                'success' => true,
                'message' => 'Tema uygulandi.',
                'theme' => [
                    'mode' => $mode,
                    'color' => $color,
                    'eye_comfort_level' => $eyeComfortLevel,
                    'combined' => $combined,
                ],
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Tema Hatasi.'];
        }
    }

    public function updateLanguage()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = App::user()->getUid();
            $locale = trim(strip_tags($_POST['locale'] ?? 'tr'));
            $languageConfig = self::getLanguageConfig();

            if (!isset($languageConfig[$locale])) {
                return ['error' => true, 'message' => 'Gecersiz dil secimi.'];
            }

            if (!DB::getSchemaBuilder()->hasColumn('users', 'language')) {
                return ['error' => true, 'message' => 'Dil sutunu henuz kurulmamis.'];
            }

            DB::table('users')
                ->where('id', $uid)
                ->update(['language' => $locale]);

            App::container()->get('langManager')->setLocale($locale);

            return [
                'success' => true,
                'message' => 'Dil guncellendi.',
                'locale' => $locale,
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Dil kaydedilemedi.'];
        }
    }

    public function updateNotifications()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = (int) App::user()->getUid();
            $preferences = [
                'dm_enabled' => ($_POST['dm_enabled'] ?? '0') === '1',
                'news_enabled' => ($_POST['news_enabled'] ?? '0') === '1',
                'system_enabled' => ($_POST['system_enabled'] ?? '0') === '1',
                'quiet_hours_enabled' => ($_POST['quiet_hours_enabled'] ?? '0') === '1',
                'quiet_start' => (string) ($_POST['quiet_start'] ?? '22:00'),
                'quiet_end' => (string) ($_POST['quiet_end'] ?? '08:00'),
            ];

            if (!Notify::savePreferences($uid, $preferences)) {
                return ['error' => true, 'message' => 'Bildirim tercihleri kaydedilemedi.'];
            }

            return [
                'success' => true,
                'message' => 'Bildirim tercihleri kaydedildi.',
                'preferences' => Notify::getPreferences($uid),
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Bildirim tercihleri kaydedilemedi.'];
        }
    }

    public function updateDmPrivacy()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = (int) App::user()->getUid();
            $allowFrom = trim(strip_tags((string) ($_POST['allow_from'] ?? DmPrivacy::ALLOW_EVERYONE)));
            $support = DmPrivacy::getSupportState();

            if ($allowFrom === 'friends') {
                return ['error' => true, 'message' => 'Arkadas altyapisi henuz aktif degil.'];
            }

            if ($allowFrom === DmPrivacy::ALLOW_PARTY && empty($support['party'])) {
                return ['error' => true, 'message' => 'Parti / ittifak altyapisi henuz aktif degil.'];
            }

            if (!DmPrivacy::savePreferences($uid, [
                'allow_from' => $allowFrom,
                'message_requests_enabled' => ($_POST['message_requests_enabled'] ?? '0') === '1',
            ])) {
                return ['error' => true, 'message' => 'DM gizlilik ayarlari kaydedilemedi.'];
            }

            return [
                'success' => true,
                'message' => 'DM gizlilik ayarlari kaydedildi.',
                'preferences' => DmPrivacy::getPreferences($uid),
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'DM gizlilik ayarlari kaydedilemedi.'];
        }
    }

    public function updateGameExperience()
    {
        try {
            if (!$this->hasValidCsrf()) {
                return ['error' => true, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyin.'];
            }

            $uid = (int) App::user()->getUid();
            $favorites = $_POST['quick_favorites'] ?? [];
            if (!is_array($favorites)) {
                $favorites = [];
            }

            $preferences = [
                'ui_density' => trim(strip_tags((string) ($_POST['ui_density'] ?? 'balanced'))),
                'animation_level' => trim(strip_tags((string) ($_POST['animation_level'] ?? 'balanced'))),
                'left_hud' => trim(strip_tags((string) ($_POST['left_hud'] ?? 'detailed'))),
                'shout_width' => trim(strip_tags((string) ($_POST['shout_width'] ?? 'balanced'))),
                'home_layout' => trim(strip_tags((string) ($_POST['home_layout'] ?? 'wide'))),
                'home_priority' => trim(strip_tags((string) ($_POST['home_priority'] ?? 'news'))),
                'quick_favorites' => $favorites,
            ];

            if (!GameExperience::savePreferences($uid, $preferences)) {
                return (object) ['error' => true, 'message' => 'Oyun deneyimi ayarlari kaydedilemedi.'];
            }

            return (object) [
                'success' => true,
                'message' => 'Oyun deneyimi ayarlari kaydedildi.',
                'preferences' => GameExperience::getPreferences($uid),
            ];
        } catch (\Exception $e) {
            return (object) ['error' => true, 'message' => 'Oyun deneyimi ayarlari kaydedilemedi.'];
        }
    }
}

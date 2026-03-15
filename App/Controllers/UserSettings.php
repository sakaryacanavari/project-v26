<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kullanıcı ayarları controller'ı.
 * Tema sistemi, profil ve şifre güncellemeleri.
 *
 * Tema formatı: mode_color (örn: dark_cyan, oled_red, aurora_amber)
 * Geçerli modlar: dark, oled, dim, magma, aurora
 * Geçerli renkler: cyan, diamond, blue, mono, red, amber, purple, pink, lavender, overdrive, blood, matrix
 */
class UserSettings extends Controller
{
    /** Geçerli tema modları */
    const VALID_MODES = ['dark', 'oled', 'dim', 'magma', 'aurora'];

    /** Geçerli tema renkleri */
    const VALID_COLORS = [
        'cyan', 'diamond', 'blue', 'mono', 'red',
        'amber', 'purple', 'pink', 'lavender',
        'overdrive', 'blood', 'matrix'
    ];

    // -------------------------------------------------------
    // Sayfa görünümleri
    // -------------------------------------------------------

    /**
     * Kullanıcı ayarları sayfasını gösterir.
     */
    public function showSettings()
    {
        $uid = $this->uid();
        $themeState = $this->getAuthenticatedUserThemeState($uid);

        return $this->render('settings/settings.html.twig', [
            'themeState'  => $themeState,
            'validModes'  => self::VALID_MODES,
            'validColors' => self::VALID_COLORS,
        ]);
    }

    // -------------------------------------------------------
    // API işlemleri
    // -------------------------------------------------------

    /**
     * Tema yapılandırmasını döndürür (GET /api/user/settings/theme).
     * localStorage önceliği: storedModeRaw || serverModeRaw
     */
    public function getThemeConfig()
    {
        $uid = $this->uid();
        $themeState = $this->getAuthenticatedUserThemeState($uid);
        return $this->success('Tema bilgisi alındı.', $themeState);
    }

    /**
     * Temayı günceller (POST /api/user/settings/theme).
     * mode_color formatında geçerli değer beklenir.
     */
    public function updateTheme()
    {
        $uid   = $this->uid();
        $theme = trim($this->input('theme', ''));

        if (!$theme) {
            return $this->error('Tema değeri gereklidir.');
        }

        // Tema string'ini ayrıştır ve doğrula
        $parsed = $this->parseThemeString($theme);
        if (!$parsed) {
            return $this->error('Geçersiz tema formatı. Beklenen: mode_color');
        }

        DB::table('users')
            ->where('id', $uid)
            ->update([
                'theme'      => $parsed['mode'] . '_' . $parsed['color'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->success('Tema güncellendi.', [
            'theme' => $parsed['mode'] . '_' . $parsed['color'],
            'mode'  => $parsed['mode'],
            'color' => $parsed['color'],
        ]);
    }

    /**
     * Profil bilgilerini günceller.
     */
    public function updateProfile()
    {
        $uid   = $this->uid();
        $nick  = trim($this->input('nick', ''));
        $email = trim($this->input('email', ''));

        if (!$nick && !$email) {
            return $this->error('Güncellenecek en az bir alan gerekli.');
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];

        if ($nick) {
            if (strlen($nick) < 3 || strlen($nick) > 30) {
                return $this->error('Kullanıcı adı 3-30 karakter arasında olmalıdır.');
            }
            if (DB::table('users')->where('nick', $nick)->where('id', '!=', $uid)->exists()) {
                return $this->error('Bu kullanıcı adı zaten kullanılıyor.');
            }
            $data['nick'] = $nick;
        }

        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->error('Geçerli bir e-posta adresi girin.');
            }
            if (DB::table('users')->where('email', $email)->where('id', '!=', $uid)->exists()) {
                return $this->error('Bu e-posta adresi zaten kullanılıyor.');
            }
            $data['email'] = $email;
        }

        DB::table('users')->where('id', $uid)->update($data);
        return $this->success('Profil güncellendi.');
    }

    /**
     * Şifreyi günceller.
     */
    public function updatePassword()
    {
        $uid         = $this->uid();
        $oldPassword = trim($this->input('old_password', ''));
        $newPassword = trim($this->input('new_password', ''));

        if (!$oldPassword || !$newPassword) {
            return $this->error('Eski ve yeni şifre zorunludur.');
        }

        if (strlen($newPassword) < 6) {
            return $this->error('Yeni şifre en az 6 karakter olmalıdır.');
        }

        $user = DB::table('users')->where('id', $uid)->first();
        if (!$user) {
            return $this->error('Kullanıcı bulunamadı.');
        }

        $hashedOld = \App\System\Utils::encryptPassword($oldPassword);
        if ($user->password !== $hashedOld) {
            return $this->error('Eski şifre hatalı.');
        }

        $hashedNew = \App\System\Utils::encryptPassword($newPassword);
        DB::table('users')
            ->where('id', $uid)
            ->update([
                'password'   => $hashedNew,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->success('Şifre başarıyla güncellendi.');
    }

    // -------------------------------------------------------
    // Yardımcı metodlar (public - omni-themes tarafından kullanılır)
    // -------------------------------------------------------

    /**
     * Tema string'ini mode ve color bileşenlerine ayrıştırır.
     * Geçersizse null döner.
     *
     * @param string $theme "mode_color" formatında (örn: "dark_cyan")
     */
    public function parseThemeString(string $theme): ?array
    {
        $parts = explode('_', $theme, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$mode, $color] = $parts;

        if (!in_array($mode, self::VALID_MODES) || !in_array($color, self::VALID_COLORS)) {
            return null;
        }

        return ['mode' => $mode, 'color' => $color];
    }

    /**
     * Giriş yapmış kullanıcının tema durumunu döndürür.
     * localStorage önceliği: storedModeRaw || serverModeRaw
     */
    public function getAuthenticatedUserThemeState(int $uid): array
    {
        $user = DB::table('users')
            ->select('theme')
            ->where('id', $uid)
            ->first();

        $serverTheme = $user ? ($user->theme ?? 'dark_cyan') : 'dark_cyan';
        $parsed = $this->parseThemeString($serverTheme) ?? ['mode' => 'dark', 'color' => 'cyan'];

        return [
            'serverModeRaw'  => $parsed['mode'],
            'serverColorRaw' => $parsed['color'],
            'serverTheme'    => $parsed['mode'] . '_' . $parsed['color'],
            'validModes'     => self::VALID_MODES,
            'validColors'    => self::VALID_COLORS,
        ];
    }
}

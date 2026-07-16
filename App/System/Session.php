<?php

namespace App\System;

use App\Models\User as UserModel;
use App\Models\CongressMember;
use App\Models\MilitiaMember;
use App\Models\Newspaper;
use App\Models\PartyMember;
use App\Models\UserItem;
use App\Models\UserMoney;
use App\Models\Region;
use App\Models\WorkOffer;
use Illuminate\Database\Capsule\Manager as DB;

class Session
{
    private $app;
    private $cache = [];

    const PURCHASE_TYPE_COMPANY = "company";
    const REMEMBER_COOKIE = "project_v26_remember";
    const REMEMBER_DAYS = 30;

    public function __construct($app)
    {
        $this->app = $app;

        ini_set("session.gc_maxlifetime", "18000");
        session_cache_limiter(false);

        session_set_cookie_params(18000, '/', $this->app->getContainer()->get('settings')['cookies.domain']);

        $redisSession = Cache::isAvailable();
        if ($redisSession) {
            ini_set('session.save_handler', 'redis');
            ini_set('session.save_path', Cache::sessionSavePath());
        }

        if (!@session_start() && $redisSession) {
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', '');
            @session_start();
        }
    }

    private function clearCache()
    {
        $this->cache = [];
    }

    private function remember($key, callable $resolver)
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $this->cache[$key] = $resolver();
        return $this->cache[$key];
    }

    /**
     * Fills user data in current session
     * @param array $user
     */
    public function fillUserData ($user, $regenerate = false)
    {
        if ($regenerate && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        unset($user["password"]);

        $_SESSION['user'] = $user;
        $_SESSION['user_id'] = isset($user['id']) ? (int) $user['id'] : null;
        $this->clearCache();
    }

    public function issueRememberToken($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->rememberTableReady()) {
            return false;
        }

        $selector = bin2hex(random_bytes(12));
        $verifier = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (self::REMEMBER_DAYS * 86400));

        DB::table('auth_remember_tokens')->insert([
            'uid' => $uid,
            'selector' => $selector,
            'token_hash' => hash('sha256', $verifier),
            'expires_at' => $expiresAt,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->setRememberCookie($selector . ':' . $verifier, time() + (self::REMEMBER_DAYS * 86400));
        return true;
    }

    public function clearRememberToken()
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $selector = $this->extractRememberSelector($raw);

        if ($selector !== '' && $this->rememberTableReady()) {
            DB::table('auth_remember_tokens')->where('selector', $selector)->delete();
        }

        $this->setRememberCookie('', time() - 3600);
    }

    private function restoreRememberedUser()
    {
        if (!empty($_SESSION['user'])) {
            return true;
        }

        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2 || !$this->rememberTableReady()) {
            return false;
        }

        $selector = $parts[0];
        $verifier = $parts[1];
        if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $verifier)) {
            $this->clearRememberToken();
            return false;
        }

        $token = DB::table('auth_remember_tokens')
            ->where('selector', $selector)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();

        if (!$token || !hash_equals((string) $token->token_hash, hash('sha256', $verifier))) {
            $this->clearRememberToken();
            return false;
        }

        $user = UserModel::find((int) $token->uid);
        if (!$user) {
            $this->clearRememberToken();
            return false;
        }

        DB::table('auth_remember_tokens')->where('id', (int) $token->id)->update([
            'last_used_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->fillUserData($user->toArray(), false);
        return true;
    }

    /**
     * Logs the user out
     */
    public function logout()
    {
        $this->clearRememberToken();
        session_destroy();
        $this->clearCache();

        App::setAjax(false);
        $this->isLogged = false;

        $previousPath = "";
        if (isset($_REQUEST["redirect"]) && !empty($_REQUEST["redirect"])) {
            $previousPath  = "?redirect=" . urlencode($_REQUEST["redirect"]);
        }

        App::redirect($this->app->getContainer()->get('router')->pathFor('login') . $previousPath);
    }

    /**
     * Returns if user is logged
     * @return bool
     */
    public function isLogged()
    {
        if (!empty($_SESSION['user'])) {
            return true;
        }

        return $this->restoreRememberedUser();
    }

    /**
     * Route middleware to guarantee that user is logged before visiting the path
     */
    public function ensureLogged()
    {
        if (!$this->isLogged())
        {
            if (App::isAjax()) {
                header("Content-Type: application/json");
                echo json_encode(['error' => 11]);
                exit;
            } else {
                $router = $this->app->getContainer()->get("router");
                $params = "";

                if (!empty($_GET)) {
                    $params = "?" . http_build_query($_GET);
                }
                App::redirect($router->pathFor('login') . $params);
            }
        }
    }

    /**
     * Gets current user data
     * @return null
     */
    public function getUser()
    {
        if (!$this->isLogged()) {
            return null;
        }

        return $_SESSION['user'];
    }

    public function setUserField($key, $value)
    {
        if (!$this->isLogged() || !isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            return;
        }

        $_SESSION['user'][$key] = $value;
        $this->clearCache();
    }

    public function getUid()
{
    $u = $this->getUser();
    return isset($u['id']) ? (int)$u['id'] : null;
	}

    /**
     * Gets user's money
     * @return UserMoney
     */
    public function getMoney ()
    {
        return $this->remember('money', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return null;
            }

            return UserMoney::where("uid", $uid)->first();
        });
    }

    /*
     * Gets user's location
     * @return array
     */
    public function getLocation ()
    {
        return $this->remember('location', function () {
            $uid = $this->getUid();
            $user = $this->getUser();
            if (empty($user["region"])) {
                return [];
            }

            return Cache::remember(
                Cache::userKey($uid, 'hud:location'),
                30,
                function () use ($user) {
                    return Region::getFullInfo($user["region"]);
                }
            );
        });
    }

    /**
     * @return WorkOffer
     */
    public function getJob ()
    {
        return $this->remember('job', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return null;
            }

            return WorkOffer::where([
                "worker" => $uid
            ])->first();
        });
    }

    /**
     * @return PartyMember
     */
    public function getPoliticalParty ()
    {
        return $this->remember('political_party', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return null;
            }

            return PartyMember::with("partyData")->where([
                "uid" => $uid
            ])->first();
        });
    }

    /**
     * @return MilitiaMember
     */
    public function getMilitia ()
    {
        return $this->remember('militia', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return null;
            }

            return MilitiaMember::with("militiaData")->where([
                "uid" => $uid
            ])->first();
        });
    }

    /**
     * @return Newspaper
     */
    public function getNewspaper ()
    {
        return $this->remember('newspaper', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return null;
            }

            return Newspaper::where([
                "uid" => $uid
            ])->first();
        });
    }

    /**
     * @return array
     */
    public function getItems ()
    {
        return $this->remember('items', function () {
            $uid = $this->getUid();
            if (!$uid) {
                return new \Illuminate\Database\Eloquent\Collection();
            }

            return UserItem::where([
                "uid" => $uid
            ])->get();
        });
    }

    /**
     * Checks if user can pay
     * @param $amount
     * @param $currency
     */
    public function buy ($amount, $currency, $purchaseType)
    {
        // get the local currency
        if ($currency == "local") {
            $currency = $this->getLocation()["country"]["currency"];
        }

        $money = $this->getMoney();

        if (empty($money[$currency]) || $money[$currency] < $amount) {
            throw new AppException(AppException::NO_ENOUGH_MONEY);
        }

        $money[$currency] -= $amount;

        if ($money->save()) {
            $this->cache['money'] = $money;
            return true;
        }

        return false;
    }

    public function isCongressist ()
    {
        $uid = $this->getUid();
        if (!$uid) {
            return false;
        }

        // Meclis üyeliği seçim ve yönetim işlemlerinden sonra değişebilir;
        // bu yetkiyi oturum cache'inden okumak yeni üyelikleri geciktirir.
        return CongressMember::where([
            "uid" => $uid
        ])->first() == true;
    }

    /**
     * Sets a session var
     * @param string $k
     * @param mixed $v
     */
    public function set($k, $v)
    {
        $_SESSION[$k] = $v;
    }

    /**
     * Gets a session var
     * @param string $k
     * @return mixed
     */
    public function get($k)
	{
    return $_SESSION[$k] ?? null;
	}

    /**
     * Deletes a session var
     * @param string $k
     */
    public function del($k)
    {
        unset($_SESSION[$k]);
    }

    public function ensureCsrfToken()
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }

    public function getCsrfToken()
    {
        return $this->ensureCsrfToken();
    }

    public function validateCsrfToken($token)
    {
        $real = $this->ensureCsrfToken();
        return is_string($token) && $token !== '' && hash_equals($real, $token);
    }

    private function rememberTableReady()
    {
        try {
            return DB::getSchemaBuilder()->hasTable('auth_remember_tokens');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function extractRememberSelector($raw)
    {
        $parts = explode(':', (string) $raw, 2);
        $selector = $parts[0] ?? '';
        return preg_match('/^[a-f0-9]{24}$/', $selector) ? $selector : '';
    }

    private function setRememberCookie($value, $expires)
    {
        $domain = (string) ($this->app->getContainer()->get('settings')['cookies.domain'] ?? '');
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => (int) $expires,
            'path' => '/',
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if ($expires <= time()) {
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        } else {
            $_COOKIE[self::REMEMBER_COOKIE] = $value;
        }
    }

    public function flash($k, $v)
    {
        if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }

        $_SESSION['_flash'][$k] = $v;
    }

    public function pullFlash($k, $default = null)
    {
        if (
            !isset($_SESSION['_flash']) ||
            !is_array($_SESSION['_flash']) ||
            !array_key_exists($k, $_SESSION['_flash'])
        ) {
            return $default;
        }

        $value = $_SESSION['_flash'][$k];
        unset($_SESSION['_flash'][$k]);

        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }

        return $value;
    }
}

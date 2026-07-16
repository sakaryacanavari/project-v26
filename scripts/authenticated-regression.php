<?php

/**
 * Local-only authenticated smoke/regression suite.
 *
 * This script creates two prefixed users, exercises real HTTP endpoints, and
 * removes every row it owns in finally(). It deliberately refuses production.
 */

use App\Models\Item;
use App\Models\User;
use App\Models\UserItem;
use App\System\App;
use App\System\MarketOrderService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;

ob_start();

if (!in_array('--allow-local', $argv, true)) {
    fwrite(STDERR, "Refused: run only with --allow-local.\n");
    exit(2);
}

$environment = getenv('APP_ENV') ?: 'production';
if ($environment !== 'development') {
    fwrite(STDERR, "Refused: authenticated regression tests require APP_ENV=development.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('REGRESSION_BASE_URL') ?: 'http://localhost'), '/');
$host = (string) parse_url($baseUrl, PHP_URL_HOST);
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Refused: base URL must point to localhost.\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/routes.php';

$db = App::container()->get('db')->getConnection();
$schema = $db->getSchemaBuilder();
$results = [];
$createdUsers = [];
$createdOffers = [];
$createdMessages = [];
$clients = [];
$cookieDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'v26-regression-' . bin2hex(random_bytes(5));
if (!@mkdir($cookieDir, 0700, true) && !is_dir($cookieDir)) {
    throw new RuntimeException('Could not create private cookie directory.');
}

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};

$test = static function (string $name, callable $callback) use (&$results): void {
    try {
        $callback();
        $results[] = ['name' => $name, 'status' => 'passed'];
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'status' => 'failed', 'message' => $e->getMessage()];
        echo "FAIL  {$name}: {$e->getMessage()}\n";
    }
};

$columns = static function (string $table) use ($schema): array {
    try {
        return $schema->hasTable($table) ? $schema->getColumnListing($table) : [];
    } catch (Throwable $e) {
        return [];
    }
};

$json = static function (array $response): ?array {
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : null;
};

$payload = static function (array $response) use ($json): ?array {
    $body = $json($response);
    if (is_array($body) && isset($body['result']) && is_array($body['result']) && array_key_exists('error', $body['result'])) {
        return $body['result'];
    }
    return $body;
};

$request = static function (array $client, string $method, string $path, array $data = [], array $extraHeaders = []) use ($baseUrl): array {
    $ch = curl_init($baseUrl . $path);
    $headers = ['Accept: application/json, text/html;q=0.9'];
    if (strpos($path, '/api/') === 0) {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $client['cookie'],
        CURLOPT_COOKIEJAR => $client['cookie'],
    ]);
    if (strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    return ['status' => $status, 'body' => (string) $body, 'content_type' => $contentType];
};

$csrfFromHtml = static function (string $html): string {
    if (preg_match('/name=["\']csrf_token["\'][^>]*value=["\']([^"\']+)/i', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/value=["\']([^"\']+)["\'][^>]*name=["\']csrf_token["\']/i', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)/i', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']csrf-token["\']/i', $html, $match)) {
        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return '';
};

$login = static function (array &$client, string $email, string $password) use ($request, $csrfFromHtml, $fail): void {
    $loginPage = $request($client, 'GET', '/login');
    if ($loginPage['status'] !== 200) {
        $fail('login page returned HTTP ' . $loginPage['status']);
    }
    $response = $request($client, 'POST', '/login', ['email' => $email, 'password' => $password]);
    $body = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($body) || (int) ($body['error'] ?? 1) !== 0) {
        $fail('login failed');
    }
    $page = $request($client, 'GET', '/gyms');
    if ($page['status'] !== 200) {
        $bodyExcerpt = trim(preg_replace('/\s+/', ' ', substr($page['body'], 0, 240)));
        $fail('authenticated page returned HTTP ' . $page['status'] . ' (' . $bodyExcerpt . ')');
    }
    $client['csrf'] = $csrfFromHtml($page['body']);
    if ($client['csrf'] === '' || !is_readable($client['cookie']) || filesize($client['cookie']) < 1) {
        $fail('session or CSRF token was not established');
    }
};

$post = static function (array $client, string $path, array $data = []) use ($request): array {
    $data['csrf_token'] = $client['csrf'] ?? '';
    return $request($client, 'POST', $path, $data);
};

$isSuccess = static function (array $response) use ($payload): bool {
    $body = $payload($response);
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($body)) {
        return false;
    }
    if (array_key_exists('error', $body)) {
        return (int) $body['error'] === 0;
    }
    return ($body['success'] ?? false) === true;
};

$isError = static function (array $response) use ($payload): bool {
    $body = $payload($response);
    return $response['status'] >= 400
        || !is_array($body)
        || (array_key_exists('error', $body) && (int) $body['error'] !== 0)
        || (($body['success'] ?? true) === false);
};

$createUser = static function (string $label) use (&$createdUsers, $db, $columns, $fail): array {
    $country = $db->table('countries')->orderBy('id')->first();
    if (!$country) {
        $fail('no country available for isolated user');
    }
    $region = $db->table('regions')->where('country', (int) $country->id)->orderBy('id')->first();
    if (!$region && !empty($country->capital)) {
        $region = $db->table('regions')->where('id', (int) $country->capital)->first();
    }
    if (!$region) {
        $fail('no region available for isolated user');
    }

    $suffix = bin2hex(random_bytes(4));
    $email = 'v26_regression_' . $label . '_' . $suffix . '@invalid.local';
    $nick = 'v26test' . substr($suffix, 0, 6);
    $password = 'V26-Regression-' . bin2hex(random_bytes(8));
    $data = [
        'email' => $email,
        'nick' => $nick,
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'status' => User::STATUS_ACTIVATED,
        'region' => (int) $region->id,
        'country_id' => (int) $country->id,
        'theme' => 'dark_cyan',
        'language' => 'tr',
        'economic_skill' => 1,
        'economic_xp' => 0,
        'strength' => 100,
    ];
    $allowed = array_flip($columns('users'));
    $now = date('Y-m-d H:i:s');
    foreach (['created_at', 'updated_at'] as $timestampColumn) {
        if (isset($allowed[$timestampColumn])) {
            $data[$timestampColumn] = $now;
        }
    }
    $data = array_intersect_key($data, $allowed);
    $uid = (int) $db->table('users')->insertGetId($data);
    if ($uid < 1) {
        $fail('isolated user creation failed');
    }
    $createdUsers[] = $uid;
    if ($columns('user_money')) {
        $money = ['uid' => $uid];
        if (in_array('gold', $columns('user_money'), true)) {
            $money['gold'] = 100;
        }
        $moneyColumns = array_flip($columns('user_money'));
        foreach (['created_at', 'updated_at'] as $timestampColumn) {
            if (isset($moneyColumns[$timestampColumn])) {
                $money[$timestampColumn] = $now;
            }
        }
        $db->table('user_money')->insert($money);
    }
    if ($columns('user_gyms')) {
        $db->table('user_gyms')->insert(['uid' => $uid]);
    }
    return ['uid' => $uid, 'email' => $email, 'password' => $password, 'country' => $country];
};

$setWallet = static function (int $uid) use ($db, $columns): void {
    $updates = [];
    foreach ($columns('user_money') as $column) {
        if ($column === 'uid' || in_array($column, ['created_at', 'updated_at'], true)) {
            continue;
        }
        $updates[$column] = $column === 'gold' ? 100 : 1000;
    }
    if ($updates) {
        $db->table('user_money')->where('uid', $uid)->update($updates);
    }
};

$deleteByUid = static function (string $table, int $uid) use ($db, $columns): void {
    if (in_array('uid', $columns($table), true)) {
        try {
            $db->table($table)->where('uid', $uid)->delete();
        } catch (Throwable $e) {
        }
    }
};

try {
    if (!$schema->hasTable('user_gym_daily_actions') || !$schema->hasTable('dm_privacy_settings')) {
        throw new RuntimeException('required migrated tables are missing; run schema-migrate first');
    }

    $first = $createUser('a');
    $second = $createUser('b');
    $setWallet($first['uid']);
    $setWallet($second['uid']);
    $clients['a'] = ['cookie' => $cookieDir . '/a.cookie', 'csrf' => ''];
    $clients['b'] = ['cookie' => $cookieDir . '/b.cookie', 'csrf' => ''];

    $test('login, session renewal and authenticated page', function () use (&$clients, $first, $second, $login, $request, $fail): void {
        $login($clients['a'], $first['email'], $first['password']);
        $login($clients['b'], $second['email'], $second['password']);
        $renewed = $request($clients['a'], 'GET', '/storage');
        if ($renewed['status'] !== 200) {
            $fail('session did not survive route change');
        }
    });

    $test('logout and re-login', function () use (&$clients, $first, $login, $request, $fail): void {
        $logout = $request($clients['a'], 'GET', '/logout');
        if ($logout['status'] < 300 || $logout['status'] >= 400) {
            $fail('logout did not redirect');
        }
        $login($clients['a'], $first['email'], $first['password']);
    });

    $test('CSRF rejects missing and invalid tokens', function () use ($clients, $request, $fail): void {
        $missing = $request($clients['a'], 'POST', '/api/gym/train');
        $invalid = $request($clients['a'], 'POST', '/api/gym/train', ['csrf_token' => 'invalid']);
        if ($missing['status'] !== 403 || $invalid['status'] !== 403) {
            $fail('CSRF rejection status was not 403');
        }
    });

    $test('free, extra and wheel daily limits', function () use ($clients, $post, $isSuccess, $isError, $fail): void {
        $free = $post($clients['a'], '/api/gym/train');
        $freeAgain = $post($clients['a'], '/api/gym/train');
        $extra = $post($clients['a'], '/api/gym/train-extra');
        $extraAgain = $post($clients['a'], '/api/gym/train-extra');
        $wheel = $post($clients['a'], '/api/gym/spin-wheel');
        $wheelAgain = $post($clients['a'], '/api/gym/spin-wheel');
        if (!$isSuccess($free) || !$isError($freeAgain) || !$isSuccess($extra) || !$isError($extraAgain) || !$isSuccess($wheel) || !$isError($wheelAgain)) {
            $fail('one or more daily limits did not behave as expected');
        }
    });

    $test('concurrent free training produces at most one reward', function () use ($clients, $baseUrl, $fail): void {
        $handles = [];
        $multi = curl_multi_init();
        for ($i = 0; $i < 2; $i++) {
            $ch = curl_init($baseUrl . '/api/gym/train');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $clients['b']['csrf']]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_COOKIEFILE => $clients['b']['cookie'],
                CURLOPT_COOKIEJAR => $clients['b']['cookie'],
                CURLOPT_TIMEOUT => 20,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = $ch;
        }
        do {
            $running = 0;
            curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running);
        $successes = 0;
        foreach ($handles as $ch) {
            $body = json_decode((string) curl_multi_getcontent($ch), true);
            if (is_array($body) && isset($body['result']) && is_array($body['result']) && array_key_exists('error', $body['result'])) {
                $body = $body['result'];
            }
            if (is_array($body) && (int) ($body['error'] ?? 1) === 0) {
                $successes++;
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        if ($successes > 1) {
            $fail('concurrent training requests both succeeded');
        }
    });

    $test('theme, eye comfort and reduced-motion persistence', function () use ($clients, $post, $json, $isSuccess, $db, $schema, $first, $fail): void {
        $response = $post($clients['a'], '/api/user/settings/theme', ['mode' => 'liquid', 'color' => 'purple', 'eye_comfort_level' => 'balanced']);
        $body = $json($response);
        $theme = (string) $db->table('users')->where('id', $first['uid'])->value('theme');
        if ($response['status'] !== 200 || !is_array($body) || (int) ($body['error'] ?? 1) !== 0 || $theme !== 'liquid_glass_balanced') {
            $fail('theme was not normalized and persisted');
        }
        $eyeComfort = $post($clients['a'], '/api/user/settings/theme', ['mode' => 'archive', 'color' => 'purple', 'eye_comfort_level' => 'intense']);
        $eyeTheme = (string) $db->table('users')->where('id', $first['uid'])->value('theme');
        if ((int) (($json($eyeComfort) ?? [])['error'] ?? 1) !== 0 || $eyeTheme !== 'archive_amber_intense') {
            $fail('eye comfort theme was not persisted');
        }
        if (!$schema->hasTable('game_experience_settings')) {
            $fail('game experience settings table is missing');
        }
        $motion = $post($clients['a'], '/api/user/settings/game-experience', ['animation_level' => 'off']);
        $motionLevel = (string) $db->table('game_experience_settings')->where('uid', $first['uid'])->value('animation_level');
        if (!$isSuccess($motion) || $motionLevel !== 'off') {
            $motionBody = trim(preg_replace('/\s+/', ' ', substr($motion['body'], 0, 220)));
            $fail('reduced-motion preference was not persisted (' . $motionBody . '; db=' . $motionLevel . ')');
        }
    });

    $test('DM privacy, send and reply', function () use (&$clients, &$createdMessages, $post, $json, $db, $first, $second, $fail): void {
        $blocked = $post($clients['b'], '/api/user/settings/dm-privacy', ['allow_from' => 'nobody', 'message_requests_enabled' => '0']);
        if ((int) (($json($blocked) ?? [])['error'] ?? 1) !== 0) {
            $fail('privacy preference could not be saved');
        }
        $denied = $post($clients['a'], '/api/messages/send', ['to_uid' => $second['uid'], 'body' => 'blocked-' . bin2hex(random_bytes(2))]);
        if ((int) (($json($denied) ?? [])['error'] ?? 0) === 0) {
            $fail('DM was allowed while recipient privacy was off');
        }
        $allowed = $post($clients['b'], '/api/user/settings/dm-privacy', ['allow_from' => 'everyone', 'message_requests_enabled' => '0']);
        if ((int) (($json($allowed) ?? [])['error'] ?? 1) !== 0) {
            $fail('privacy preference could not be restored');
        }
        $sent = $post($clients['a'], '/api/messages/send', ['to_uid' => $second['uid'], 'body' => 'hello-' . bin2hex(random_bytes(2))]);
        if ((int) (($json($sent) ?? [])['error'] ?? 1) !== 0) {
            $fail('DM send failed');
        }
        $reply = $post($clients['b'], '/api/messages/send', ['to_uid' => $first['uid'], 'body' => 'reply-' . bin2hex(random_bytes(2))]);
        if ((int) (($json($reply) ?? [])['error'] ?? 1) !== 0) {
            $fail('DM reply failed');
        }
        foreach ([$sent, $reply] as $response) {
            $body = $json($response);
            if (!empty($body['id'])) {
                $createdMessages[] = (int) $body['id'];
            }
        }
    });

    $test('market create, buy, cancel and idempotent expiry', function () use (&$createdOffers, $schema, $db, $clients, $post, $json, $isSuccess, $isError, $first, $second, $fail): void {
        if (!$schema->hasTable('item_offers') || !$schema->hasTable('market_order_events') || !$schema->hasTable('user_items')) {
            $fail('market schema is not available');
        }
        $item = Item::where(['id' => 1, 'canBeSold' => true]);
        if (empty($item)) {
            $fail('test item is not sellable');
        }
        UserItem::where(['uid' => $first['uid'], 'item' => 1, 'quality' => 1])->delete();
        UserItem::create(['uid' => $first['uid'], 'item' => 1, 'quality' => 1, 'quantity' => 10]);

        $sell = $post($clients['a'], '/api/company/market/sell', ['item' => 1, 'quantity' => 3, 'quality' => 1, 'price' => '12.34', 'duration_hours' => 24]);
        $open = $db->table('item_offers')->where('uid', $first['uid'])->where('status', 'open')->orderByDesc('id')->first();
        if (!$isSuccess($sell) || !$open) {
            $fail('market sell order was not created');
        }
        $createdOffers[] = (int) $open->id;
        $buy = $post($clients['b'], '/api/company/market/buy', ['id' => (int) $open->id, 'quantity' => 1]);
        if (!$isSuccess($buy)) {
            $fail('market purchase failed');
        }

        UserItem::where(['uid' => $first['uid'], 'item' => 1, 'quality' => 1])->delete();
        UserItem::create(['uid' => $first['uid'], 'item' => 1, 'quality' => 1, 'quantity' => 4]);
        $sellCancel = $post($clients['a'], '/api/company/market/sell', ['item' => 1, 'quantity' => 2, 'quality' => 1, 'price' => '12.35', 'duration_hours' => 24]);
        $cancelOffer = $db->table('item_offers')->where('uid', $first['uid'])->where('status', 'open')->where('price', '12.35')->orderByDesc('id')->first();
        if (!$isSuccess($sellCancel) || !$cancelOffer) {
            $fail('cancel test order was not created');
        }
        $createdOffers[] = (int) $cancelOffer->id;
        $cancel = $post($clients['a'], '/api/company/market/cancel', ['id' => (int) $cancelOffer->id]);
        $cancelAgain = $post($clients['a'], '/api/company/market/cancel', ['id' => (int) $cancelOffer->id]);
        $closed = $db->table('item_offers')->where('id', (int) $cancelOffer->id)->first();
        if (!$isSuccess($cancel) || !$isError($cancelAgain) || !$closed || $closed->status !== 'cancelled' || (int) $closed->quantity !== 0) {
            $fail('market cancel was not idempotent');
        }

        UserItem::where(['uid' => $first['uid'], 'item' => 1, 'quality' => 1])->delete();
        UserItem::create(['uid' => $first['uid'], 'item' => 1, 'quality' => 1, 'quantity' => 2]);
        $sellExpire = $post($clients['a'], '/api/company/market/sell', ['item' => 1, 'quantity' => 1, 'quality' => 1, 'price' => '12.36', 'duration_hours' => 24]);
        $expireOffer = $db->table('item_offers')->where('uid', $first['uid'])->where('status', 'open')->where('price', '12.36')->orderByDesc('id')->first();
        if (!$isSuccess($sellExpire) || !$expireOffer) {
            $fail('expiry test order was not created');
        }
        $createdOffers[] = (int) $expireOffer->id;
        $db->table('item_offers')->where('id', (int) $expireOffer->id)->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $expired = MarketOrderService::expireDueOrders($first['uid']);
        $expiredAgain = MarketOrderService::expireDueOrders($first['uid']);
        if ($expired !== 1 || $expiredAgain !== 0) {
            $fail('market expiry was not idempotent');
        }
    });

    $test('controlled 500 handler', function () use ($fail): void {
        $slim = App::getInstance()->getSlimApp();
        $slim->get('/__v26_regression_500', function () {
            throw new RuntimeException('regression probe');
        });
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/__v26_regression_500');
        $response = $slim->handle($request);
        if ($response->getStatusCode() !== 500) {
            $fail('500 handler returned HTTP ' . $response->getStatusCode());
        }
    });
} finally {
    $messageIds = array_values(array_unique($createdMessages));
    if ($messageIds && $schema->hasTable('messages')) {
        $db->table('messages')->whereIn('id', $messageIds)->delete();
    }
    foreach ($createdOffers as $offerId) {
        try {
            $offer = $db->table('item_offers')->where('id', (int) $offerId)->first();
            if ($offer && (string) $offer->status === 'open') {
                MarketOrderService::cancel((int) $offerId, (int) $offer->uid);
            }
        } catch (Throwable $e) {
        }
        if ($schema->hasTable('market_order_events')) {
            $db->table('market_order_events')->where('offer_id', (int) $offerId)->delete();
        }
        if ($schema->hasTable('item_offers')) {
            $db->table('item_offers')->where('id', (int) $offerId)->delete();
        }
    }
    foreach ($createdUsers as $uid) {
        foreach (['user_items', 'user_money', 'user_gyms', 'user_trainings', 'user_gym_daily_actions', 'dm_privacy_settings', 'game_experience_settings', 'notification_preferences', 'user_storage_settings', 'notifications'] as $table) {
            $deleteByUid($table, (int) $uid);
        }
        if ($schema->hasTable('messages')) {
            foreach (['from_uid', 'to_uid'] as $column) {
                if (in_array($column, $columns('messages'), true)) {
                    $db->table('messages')->where($column, (int) $uid)->delete();
                }
            }
        }
        $db->table('users')->where('id', (int) $uid)->delete();
    }
    foreach (glob($cookieDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($cookieDir);
}

$failed = array_values(array_filter($results, static function (array $row): bool {
    return $row['status'] !== 'passed';
}));
$residualUsers = $createdUsers
    ? (int) $db->table('users')->whereIn('id', array_map('intval', $createdUsers))->count()
    : 0;
$residualOffers = $createdOffers && $schema->hasTable('item_offers')
    ? (int) $db->table('item_offers')->whereIn('id', array_map('intval', $createdOffers))->count()
    : 0;
$residualMessages = $messageIds && $schema->hasTable('messages')
    ? (int) $db->table('messages')->whereIn('id', $messageIds)->count()
    : 0;
$cleanup = ($residualUsers + $residualOffers + $residualMessages) === 0 ? 'clean' : 'residual_data';
echo json_encode([
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
    'cleanup' => $cleanup,
    'residual' => [
        'users' => $residualUsers,
        'offers' => $residualOffers,
        'messages' => $residualMessages,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$exitCode = $failed ? 1 : 0;
ob_end_flush();
exit($exitCode);

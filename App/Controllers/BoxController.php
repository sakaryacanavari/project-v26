<?php

namespace App\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use App\System\App as AppController;
use App\System\Controller;

class BoxController extends Controller
{
    private $boxPrice = 10;
    private $pityThreshold = 10; // Orijinal sınır korundu
    
    // YENİ: Rüşvet bedeli
    private $bribePrice = 5; 

    // Upgrader base chances
    private $upgradeChanceCommonToUncommon = 0.55;
    private $upgradeChanceUncommonToRare   = 0.30;

    // Upgrade Protection
    private $upgradeProtectionBonus = 0.10; // per fail
    private $upgradeProtectionCap   = 0.90; // max

    // Scrap (duplicate) dönüşüm oranları (istersen değiştir)
    private $scrapByRarity = [
        'rarity-common'   => 1,
        'rarity-uncommon' => 3,
        'rarity-rare'     => 10,
    ];

    private $itemsPool = [
        // YENİ: Tuzak Eşya (Sadece bu eklendi)
        ['id' => 99, 'type' => 'junk',     'qty' => 1,    'name' => 'Bozuk Mühimmat', 'icon' => '🗑️', 'rarity' => 'rarity-junk',     'weight' => 400],

        // ESKİLER (İsimler, ikonlar ve ağırlıklar tamamen orijinal)
        ['id' => 1,  'type' => 'item',     'item' => 2, 'quality' => 3, 'qty' => 10,  'name' => 'Q3 Silah x10',   'icon' => '🔫', 'rarity' => 'rarity-common',    'weight' => 320],
        ['id' => 2,  'type' => 'item',     'item' => 2, 'quality' => 5, 'qty' => 5,   'name' => 'Q5 Silah x5',    'icon' => '💣', 'rarity' => 'rarity-uncommon',  'weight' => 170],

        ['id' => 10, 'type' => 'gold',     'qty' => 5,    'name' => '5 Gold',         'icon' => '🪙', 'rarity' => 'rarity-common',    'weight' => 220],
        ['id' => 12, 'type' => 'gold',     'qty' => 10,   'name' => '10 Gold',        'icon' => '🪙', 'rarity' => 'rarity-uncommon',  'weight' => 120],
        ['id' => 11, 'type' => 'gold',     'qty' => 50,   'name' => '50 Gold',        'icon' => '💰', 'rarity' => 'rarity-rare',      'weight' => 40],

        ['id' => 20, 'type' => 'currency', 'qty' => 100,  'name' => 'ESP +100',       'icon' => '💵', 'rarity' => 'rarity-common',    'weight' => 220],
        ['id' => 21, 'type' => 'currency', 'qty' => 500,  'name' => 'ESP +500',       'icon' => '💶', 'rarity' => 'rarity-uncommon',  'weight' => 90],
        ['id' => 22, 'type' => 'currency', 'qty' => 2500, 'name' => 'ESP +2500',      'icon' => '💷', 'rarity' => 'rarity-rare',      'weight' => 14],

        ['id' => 30, 'type' => 'health',   'qty' => 10,   'name' => 'Can +10',        'icon' => '❤️', 'rarity' => 'rarity-common',    'weight' => 150],
        ['id' => 31, 'type' => 'health',   'qty' => 30,   'name' => 'Can +30',        'icon' => '💖', 'rarity' => 'rarity-uncommon',  'weight' => 55],

        ['id' => 40, 'type' => 'energy',   'qty' => 10,   'name' => 'Enerji +10',     'icon' => '⚡', 'rarity' => 'rarity-common',    'weight' => 150],
        ['id' => 41, 'type' => 'energy',   'qty' => 30,   'name' => 'Enerji +30',     'icon' => '🔋', 'rarity' => 'rarity-uncommon',  'weight' => 55],
    ];

    public function index()
    {
        $session = AppController::session();
        $userId = $session ? (int)$session->getUid() : 0;

        $pityCount = 0;
        $scrapTotal = 0;
        $lastWonItemId = 0;
        $lastWonRarity = "";
        $upgradeFailCount = 0;

        if ($userId > 0) {
            $user = DB::table('users')->where('id', $userId)->first();
            $pityCount = (int)($user->pity_count ?? 0);

            if ($this->hasColumn('users', 'scrap_total')) {
                $scrapTotal = (int)($user->scrap_total ?? 0);
            }

            if ($this->hasColumn('users', 'upgrade_fail_count')) {
                $upgradeFailCount = (int)($user->upgrade_fail_count ?? 0);
            }

            $last = $this->getLastBoxLog($userId);
            if ($last) {
                $lastWonItemId = (int)($last->item_id ?? 0);
                $lastWonRarity = (string)($last->rarity ?? "");
            }
        }

        return $this->render('box/index.html.twig', [
            'itemsPool'          => json_encode(array_values($this->itemsPool)),
            'pityCount'          => $pityCount,
            'pityThreshold'      => $this->pityThreshold, // Ön yüze limiti gönderiyoruz
            'bribePrice'         => $this->bribePrice,    // YENİ: Rüşvet fiyatını gönderiyoruz
            'scrapTotal'         => $scrapTotal,
            'lastWonItemId'      => $lastWonItemId,
            'lastWonRarity'      => json_encode($lastWonRarity),
            'upgradeFailCount'   => $upgradeFailCount,
        ]);
    }

    // YENİ: RÜŞVET SİSTEMİ EKLENDİ
    public function bribeMerchant()
    {
        $session = AppController::session();
        if (method_exists($session, 'ensureLogged')) {
            $session->ensureLogged();
        }

        $userId = (int)$session->getUid();
        $res = new \stdClass();
        $res->error = 1;
        $res->message = 'İşlem başarısız.';

        try {
            $out = DB::connection()->transaction(function () use ($userId) {
                $r = new \stdClass();

                $money = DB::table('user_money')->where('uid', $userId)->lockForUpdate()->first();
                if (!$money || (float)$money->gold < (float)$this->bribePrice) {
                    throw new \Exception("Yetersiz Gold. (Gereken: {$this->bribePrice} Gold)");
                }

                $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                $pityCount = (int)($user->pity_count ?? 0);

                if ($pityCount >= $this->pityThreshold) {
                    throw new \Exception('Şansın zaten en yüksek seviyede. Önce kasayı açmalısın!');
                }

                DB::table('user_money')->where('uid', $userId)->decrement('gold', $this->bribePrice);
                
                $newPity = $pityCount + 1;
                DB::table('users')->where('id', $userId)->update(['pity_count' => $newPity]);

                $r->error = 0;
                $r->message = 'Tüccar gülümsedi. Şans barın arttı!';
                $r->pity_count = $newPity;
                
                return $r;
            });
            return $out;
        } catch (\Exception $e) {
            $res->message = $e->getMessage();
            return $res;
        } catch (\Throwable $e) {
            $res->message = 'Bağlantı koptu...';
            return $res;
        }
    }

    public function openBox()
    {
        $session = AppController::session();
        if (method_exists($session, 'ensureLogged')) {
            $session->ensureLogged();
        }

        $userId = (int)$session->getUid();

        $res = new \stdClass();
        $res->error = 1;
        $res->message = 'İşlem başarısız.';

        try {
            $out = DB::connection()->transaction(function () use ($userId) {
                $r = new \stdClass();

                $money = DB::table('user_money')
                    ->where('uid', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$money || (float)$money->gold < (float)$this->boxPrice) {
                    throw new \Exception('Yetersiz Gold. (Gereken: ' . $this->boxPrice . ' Gold)');
                }

                $user = DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first();

                $pityCount = (int)($user->pity_count ?? 0);

                DB::table('user_money')->where('uid', $userId)->decrement('gold', $this->boxPrice);

                if ($pityCount >= $this->pityThreshold) {
                    $rareItems = array_values(array_filter($this->itemsPool, function ($item) {
                        return ($item['rarity'] ?? '') === 'rarity-rare';
                    }));
                    $winnerItem = $this->pickWeighted($rareItems);
                } else {
                    $winnerItem = $this->pickWeighted($this->itemsPool);
                }

                $duplicateConverted = false;
                $scrapGained = 0;
                $duplicateCount = 0;

                if (($winnerItem['type'] ?? '') === 'item') {
                    $check = $this->checkDuplicateCount($userId, $winnerItem);
                    $duplicateCount = $check['count'];

                    if ($duplicateCount > 0) {
                        $duplicateConverted = true;
                        $scrapGained = $this->scrapForRarity((string)($winnerItem['rarity'] ?? 'rarity-common'));

                        if ($this->hasColumn('users', 'scrap_total')) {
                            DB::table('users')->where('id', $userId)->increment('scrap_total', $scrapGained);
                        }

                        $newPity = (($winnerItem['rarity'] ?? '') === 'rarity-rare') ? 0 : ($pityCount + 1);
                        DB::table('users')->where('id', $userId)->update(['pity_count' => $newPity]);

                        $this->safeLog($userId, $winnerItem);

                        $r->error = 0;
                        $r->spin_seed = $this->makeSpinSeed();
                        $r->winner_index = $this->findWinnerIndex((int)$winnerItem['id']);
                        $r->winner_item = $winnerItem;
                        $r->pity_count = $newPity;
                        $r->last_won_item_id = (int)$winnerItem['id'];
                        $r->last_won_rarity = (string)($winnerItem['rarity'] ?? '');
                        $r->duplicate_converted = true;
                        $r->scrap_gained = $scrapGained;
                        $r->duplicate_count = $duplicateCount;
                        $r->scrap_total = $this->getUserScrapTotal($userId);

                        return $r;
                    }
                }

                // YENİ: Eğer Junk (Çöp) çıkmadıysa ödülü uygula.
                if (($winnerItem['type'] ?? '') !== 'junk') {
                    $apply = $this->applyReward($userId, $winnerItem);
                    if ($apply !== true) {
                        $errorMsg = is_string($apply) ? $apply : 'Ödül işlenemedi.';
                        throw new \Exception($errorMsg);
                    }
                }

                $this->safeLog($userId, $winnerItem);

                $newPity = (($winnerItem['rarity'] ?? '') === 'rarity-rare') ? 0 : ($pityCount + 1);
                DB::table('users')->where('id', $userId)->update(['pity_count' => $newPity]);

                $r->error = 0;
                $r->spin_seed = $this->makeSpinSeed();
                $r->winner_index = $this->findWinnerIndex((int)$winnerItem['id']);
                $r->winner_item = $winnerItem;
                $r->pity_count = $newPity;

                $r->last_won_item_id = (int)$winnerItem['id'];
                $r->last_won_rarity  = (string)($winnerItem['rarity'] ?? '');

                $r->duplicate_converted = false;
                $r->scrap_gained = 0;
                $r->duplicate_count = 0;
                $r->scrap_total = $this->getUserScrapTotal($userId);

                return $r;
            });

            return $out;
        } catch (\Exception $e) {
            $res->message = $e->getMessage();
            return $res;
        } catch (\Throwable $e) {
            $res->message = 'Sistemsel bir hata oluştu, lütfen daha sonra tekrar deneyin.';
            return $res;
        }
    }

    public function upgrade()
    {
        $session = AppController::session();
        if (method_exists($session, 'ensureLogged')) {
            $session->ensureLogged();
        }

        $userId = (int)$session->getUid();

        $body = $_POST ?? [];
        $itemId = isset($body['item_id']) ? (int)$body['item_id'] : 0;

        $res = new \stdClass();
        $res->error = 1;
        $res->message = 'Geçersiz istek.';

        if ($itemId <= 0) return $res;

        try {
            $out = DB::connection()->transaction(function () use ($userId, $itemId) {
                $r = new \stdClass();

                $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                DB::table('user_money')->where('uid', $userId)->lockForUpdate()->first();

                $last = $this->getLastBoxLog($userId, true);
                if (!$last) throw new \Exception('Yükseltilecek bir eşya bulunamadı.');

                $lastItemId = (int)($last->item_id ?? 0);
                if ($lastItemId !== $itemId) {
                    throw new \Exception('Yükseltme için son kazanılan eşya geçerli değil.');
                }

                $current = $this->getPoolItemById($itemId);
                if (!$current) throw new \Exception('Eşya bulunamadı.');

                $type = (string)($current['type'] ?? '');
                // YENİ: Junk (Çöp) eşyaların upgrade edilmesini engelle
                if ($type === 'currency' || $type === 'junk') {
                    throw new \Exception('Bu ödül yükseltilemez.');
                }

                $currentRarity = (string)($current['rarity'] ?? 'rarity-common');

                $failCount = 0;
                if ($this->hasColumn('users', 'upgrade_fail_count')) {
                    $failCount = (int)($user->upgrade_fail_count ?? 0);
                }

                $chance = $this->upgradeChanceFor($currentRarity, $failCount);
                if ($chance <= 0) {
                    throw new \Exception('Bu seviye yükseltilemez.');
                }

                $target = $this->getUpgradeTarget($current);
                if (!$target) {
                    throw new \Exception('Üst seviye bulunamadı.');
                }

                $roll = random_int(1, 1000000) / 1000000;
                $win = ($roll <= $chance);

                $rollbackOk = $this->rollbackReward($userId, $current);
                if ($rollbackOk !== true) {
                    $msg = is_string($rollbackOk) ? $rollbackOk : 'Geri alma başarısız.';
                    throw new \Exception($msg);
                }

                if ($win) {
                    $applyOk = $this->applyReward($userId, $target);
                    if ($applyOk !== true) {
                        $msg = is_string($applyOk) ? $applyOk : 'Ödül işlenemedi.';
                        throw new \Exception($msg);
                    }
                }

                if ($this->hasColumn('users', 'upgrade_fail_count')) {
                    if ($win) {
                        DB::table('users')->where('id', $userId)->update(['upgrade_fail_count' => 0]);
                        $failCountAfter = 0;
                    } else {
                        DB::table('users')->where('id', $userId)->increment('upgrade_fail_count');
                        $failCountAfter = $failCount + 1;
                    }
                } else {
                    $failCountAfter = $failCount;
                }

                $r->error = 0;
                $r->win = $win ? 1 : 0;
                $r->chance = $chance;
                $r->from_item = $current;
                $r->to_item = $target;

                $r->upgrade_fail_count_before = $failCount;
                $r->upgrade_fail_count_after  = $failCountAfter;

                $r->scrap_total = $this->getUserScrapTotal($userId);

                return $r;
            });

            return $out;
        } catch (\Exception $e) {
            $res->message = $e->getMessage();
            return $res;
        } catch (\Throwable $e) {
            $res->message = 'Sistemsel bir hata oluştu, lütfen daha sonra tekrar deneyin.';
            return $res;
        }
    }

    private function pickWeighted(array $pool): array
    {
        $total = 0;
        foreach ($pool as $it) {
            $w = (int)($it['weight'] ?? 0);
            if ($w > 0) $total += $w;
        }

        if ($total <= 0) return (array)reset($pool);

        $rand = random_int(1, $total);
        $cur = 0;

        foreach ($pool as $it) {
            $cur += (int)($it['weight'] ?? 0);
            if ($rand <= $cur) return $it;
        }

        return (array)end($pool);
    }

    private function findWinnerIndex(int $id): int
    {
        foreach ($this->itemsPool as $i => $it) {
            if ((int)$it['id'] === $id) return (int)$i;
        }
        return 0;
    }

    private function applyReward(int $userId, array $winner)
    {
        $type = (string)($winner['type'] ?? '');
        $qty  = (int)($winner['qty'] ?? 0);

        if ($qty <= 0) return 'Geçersiz ödül miktarı.';

        if ($type === 'gold') {
            DB::table('user_money')->where('uid', $userId)->increment('gold', $qty);
            return true;
        }

        if ($type === 'currency') {
            DB::table('user_money')->where('uid', $userId)->increment('esp', $qty);
            return true;
        }

        if ($type === 'health') {
            DB::table('users')->where('id', $userId)->increment('health', $qty);
            return true;
        }

        if ($type === 'energy') {
            DB::table('users')->where('id', $userId)->increment('energy', $qty);
            return true;
        }

        if ($type === 'item') {
            $itemId  = (int)($winner['item'] ?? 0);
            $quality = (int)($winner['quality'] ?? 0);

            if ($itemId <= 0 || $quality <= 0) return 'Geçersiz item ödülü.';

            $existing = DB::table('user_items')
                ->where('uid', $userId)
                ->where('item', $itemId)
                ->where('quality', $quality)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                DB::table('user_items')->where('id', $existing->id)->increment('quantity', $qty);
            } else {
                DB::table('user_items')->insert([
                    'uid' => $userId,
                    'item' => $itemId,
                    'quality' => $quality,
                    'quantity' => $qty
                ]);
            }
            return true;
        }

        return 'Bilinmeyen ödül tipi.';
    }

    private function rollbackReward(int $userId, array $winner)
    {
        $type = (string)($winner['type'] ?? '');
        $qty  = (int)($winner['qty'] ?? 0);
        if ($qty <= 0) return 'Geçersiz ödül miktarı.';

        if ($type === 'gold') {
            $money = DB::table('user_money')->where('uid', $userId)->lockForUpdate()->first();
            $cur = (float)($money->gold ?? 0);
            $new = max(0, $cur - $qty);
            DB::table('user_money')->where('uid', $userId)->update(['gold' => $new]);
            return true;
        }

        if ($type === 'health') {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $cur = (int)($user->health ?? 0);
            $new = max(0, $cur - $qty);
            DB::table('users')->where('id', $userId)->update(['health' => $new]);
            return true;
        }

        if ($type === 'energy') {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $cur = (int)($user->energy ?? 0);
            $new = max(0, $cur - $qty);
            DB::table('users')->where('id', $userId)->update(['energy' => $new]);
            return true;
        }

        if ($type === 'item') {
            $itemId  = (int)($winner['item'] ?? 0);
            $quality = (int)($winner['quality'] ?? 0);

            if ($itemId <= 0 || $quality <= 0) return 'Geçersiz item ödülü.';

            $existing = DB::table('user_items')
                ->where('uid', $userId)
                ->where('item', $itemId)
                ->where('quality', $quality)
                ->lockForUpdate()
                ->first();

            if (!$existing) return 'Eşya envanterde bulunamadı.';

            $curQty = (int)($existing->quantity ?? 0);
            $newQty = $curQty - $qty;

            if ($newQty > 0) {
                DB::table('user_items')->where('id', $existing->id)->update(['quantity' => $newQty]);
            } else {
                DB::table('user_items')->where('id', $existing->id)->delete();
            }
            return true;
        }

        return 'Bu ödül geri alınamaz.';
    }

    private function getUpgradeTarget(array $current): ?array
    {
        $id = (int)($current['id'] ?? 0);
        if ($id <= 0) return null;

        if (($current['type'] ?? '') === 'gold') {
            if ($id === 10) return $this->getPoolItemById(12);
            if ($id === 12) return $this->getPoolItemById(11);
            return null;
        }

        if (($current['type'] ?? '') === 'health') {
            if ($id === 30) return $this->getPoolItemById(31);
            return null;
        }

        if (($current['type'] ?? '') === 'energy') {
            if ($id === 40) return $this->getPoolItemById(41);
            return null;
        }

        if (($current['type'] ?? '') === 'item') {
            if ($id === 1) return $this->getPoolItemById(2);
            return null;
        }

        return null;
    }

    private function upgradeChanceFor(string $rarity, int $failCount): float
    {
        if ($rarity === 'rarity-common') {
            $base = (float)$this->upgradeChanceCommonToUncommon;
        } elseif ($rarity === 'rarity-uncommon') {
            $base = (float)$this->upgradeChanceUncommonToRare;
        } else {
            return 0.0;
        }

        $chance = $base + ($failCount * (float)$this->upgradeProtectionBonus);
        if ($chance > (float)$this->upgradeProtectionCap) $chance = (float)$this->upgradeProtectionCap;
        if ($chance < 0) $chance = 0.0;

        return $chance;
    }

    private function getPoolItemById(int $id): ?array
    {
        foreach ($this->itemsPool as $it) {
            if ((int)($it['id'] ?? 0) === $id) return $it;
        }
        return null;
    }

    private function safeLog(int $userId, array $winner): void
    {
        try {
            $col = $this->boxLogsUserColumn();
            DB::table('box_logs')->insert([
                $col => $userId,
                'item_id' => (int)($winner['id'] ?? 0),
                'item_name' => (string)($winner['name'] ?? ''),
                'rarity' => (string)($winner['rarity'] ?? ''),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
        }
    }

    private function getLastBoxLog(int $userId, bool $lockForUpdate = false)
    {
        try {
            $col = $this->boxLogsUserColumn();
            $q = DB::table('box_logs')->where($col, $userId)->orderBy('id', 'desc')->limit(1);
            if ($lockForUpdate) $q->lockForUpdate();
            return $q->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function boxLogsUserColumn(): string
    {
        $candidates = ['uid', 'user_id', 'from_uid'];

        foreach ($candidates as $c) {
            if ($this->hasColumn('box_logs', $c)) return $c;
        }

        return 'uid';
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $schema = DB::connection()->getSchemaBuilder();
            return $schema->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function makeSpinSeed(): int
    {
        return (int)(time() ^ random_int(1, 2147483647));
    }

    private function getUserScrapTotal(int $userId): int
    {
        if (!$this->hasColumn('users', 'scrap_total')) return 0;
        try {
            $u = DB::table('users')->where('id', $userId)->first();
            return (int)($u->scrap_total ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function scrapForRarity(string $rarity): int
    {
        return (int)($this->scrapByRarity[$rarity] ?? 1);
    }

    private function checkDuplicateCount(int $userId, array $winner): array
    {
        $out = ['count' => 0];

        try {
            $itemId  = (int)($winner['item'] ?? 0);
            $quality = (int)($winner['quality'] ?? 0);
            if ($itemId <= 0 || $quality <= 0) return $out;

            $existing = DB::table('user_items')
                ->where('uid', $userId)
                ->where('item', $itemId)
                ->where('quality', $quality)
                ->lockForUpdate()
                ->first();

            if ($existing) $out['count'] = 1;
        } catch (\Throwable $e) {
        }

        return $out;
    }
}
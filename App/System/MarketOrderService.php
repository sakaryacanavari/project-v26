<?php

namespace App\System;

use App\Models\ItemOffer;
use App\Models\UserItem;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Shared server-side lifecycle rules for storage sale orders.
 */
final class MarketOrderService
{
    const STATUS_OPEN = 'open';
    const STATUS_PARTIAL = 'partial';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const DEFAULT_STORAGE_CAPACITY = 10000;

    private static $schemaAvailable = null;

    /**
     * Read-only schema check for HTTP requests. Schema changes belong to CLI maintenance.
     */
    public static function schemaAvailable()
    {
        if (self::$schemaAvailable !== null) {
            return self::$schemaAvailable;
        }

        try {
            $tables = DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)',
                ['item_offers', 'market_order_events', 'user_storage_settings']
            );
            $tableNames = [];
            foreach ($tables as $table) {
                $tableNames[(string) ($table->TABLE_NAME ?? '')] = true;
            }

            $requiredTablesReady = isset($tableNames['item_offers'])
                && isset($tableNames['market_order_events'])
                && isset($tableNames['user_storage_settings']);

            if (!$requiredTablesReady) {
                self::$schemaAvailable = false;
                return false;
            }

            $columns = DB::select('SHOW COLUMNS FROM `item_offers`');
            $columnNames = [];
            foreach ($columns as $column) {
                $columnNames[(string) ($column->Field ?? '')] = true;
            }

            self::$schemaAvailable = isset($columnNames['status'])
                && isset($columnNames['listed_quantity'])
                && isset($columnNames['expires_at'])
                && isset($columnNames['closed_at']);
        } catch (\Throwable $e) {
            self::$schemaAvailable = false;
        }

        return self::$schemaAvailable;
    }

    public static function activeOffersQuery($countryId = null)
    {
        $now = date('Y-m-d H:i:s');
        $query = ItemOffer::where('status', self::STATUS_OPEN)
            ->where('quantity', '>', 0)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });

        if ($countryId !== null) {
            $query->where('country', (int) $countryId);
        }

        return $query;
    }

    public static function storageSnapshot($uid, $countryId)
    {
        $uid = (int) $uid;
        $ready = self::schemaAvailable();

        $items = UserItem::where('uid', $uid)->get();
        $itemsArray = is_object($items) && method_exists($items, 'toArray') ? $items->toArray() : (array) $items;

        $used = self::usedCapacity($uid);
        $capacity = $ready ? self::capacityForUser($uid) : max(self::DEFAULT_STORAGE_CAPACITY, (int) $used);
        $activeOrders = [];
        $history = [];
        $comparisons = [];

        if ($ready) {
            $orders = self::activeOffersQuery()
                ->where('uid', $uid)
                ->orderBy('expires_at', 'asc')
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($orders as $order) {
                $row = $order->toArray();
                $row['remaining_value'] = round((float) $order->price * (int) $order->quantity, 2);
                $row['listed_value'] = round((float) $order->price * (int) max($order->listed_quantity, $order->quantity), 2);
                $country = $order->relationLoaded('country') ? $order->getRelation('country') : null;
                $row['currency'] = strtoupper((string) ($country->currency ?? ''));
                $activeOrders[] = $row;
            }

            $historyRows = DB::table('market_order_events')
                ->where('uid', $uid)
                ->whereIn('event_type', ['sale', 'cancel', 'expire'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            foreach ($historyRows as $historyRow) {
                $history[] = (array) $historyRow;
            }

            $itemIds = [];
            foreach ($itemsArray as $item) {
                $itemIds[] = (int) ($item['item'] ?? 0);
            }
            $comparisons = self::priceComparisons($countryId, $itemIds);
        }

        $percent = $capacity > 0 ? round(min(100, ($used / $capacity) * 100), 1) : 100;
        return [
            'items' => $itemsArray,
            'activeOrders' => $activeOrders,
            'history' => $history,
            'priceComparisons' => $comparisons,
            'storageCapacity' => [
                'used' => (int) $used,
                'total' => (int) $capacity,
                'remaining' => max(0, (int) $capacity - (int) $used),
                'percent' => $percent,
                'status' => $percent >= 100 ? 'full' : ($percent >= 90 ? 'near' : 'normal'),
            ],
        ];
    }

    public static function priceComparisons($countryId, array $itemIds)
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($itemIds) || !self::schemaAvailable()) {
            return [];
        }

        $rows = self::activeOffersQuery((int) $countryId)
            ->whereIn('item', $itemIds)
            ->get(['item', 'quality', 'price']);

        $groups = [];
        foreach ($rows as $row) {
            $key = (int) $row->item . ':' . (int) $row->quality;
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = (float) $row->price;
        }

        $result = [];
        foreach ($groups as $key => $prices) {
            $result[$key] = [
                'minimum' => round(min($prices), 2),
                'average' => round(array_sum($prices) / count($prices), 2),
                'count' => count($prices),
            ];
        }
        return $result;
    }

    public static function createOrder($uid, $itemId, $quantity, $quality, $price, $countryId, $durationHours)
    {
        if (!self::schemaAvailable()) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $durationHours = (int) $durationHours;
        if (!in_array($durationHours, [24, 72, 168], true)) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $expiresAt = date('Y-m-d H:i:s', time() + ($durationHours * 3600));
        DB::beginTransaction();
        try {
            $inventory = UserItem::where([
                'uid' => (int) $uid,
                'item' => (int) $itemId,
                'quality' => (int) $quality,
            ])->lockForUpdate()->first();

            if (!$inventory || (int) $inventory->quantity < (int) $quantity) {
                throw new AppException(AppException::INVALID_DATA);
            }

            $existing = ItemOffer::where([
                'uid' => (int) $uid,
                'item' => (int) $itemId,
                'quality' => (int) $quality,
                'price' => $price,
                'country' => (int) $countryId,
                'status' => self::STATUS_OPEN,
                'expires_at' => $expiresAt,
            ])->lockForUpdate()->first();

            if ($existing) {
                $existing->quantity = (int) $existing->quantity + (int) $quantity;
                $existing->listed_quantity = (int) max($existing->listed_quantity, 0) + (int) $quantity;
                $existing->save();
                $orderId = (int) $existing->id;
            } else {
                $order = ItemOffer::create([
                    'item' => (int) $itemId,
                    'uid' => (int) $uid,
                    'price' => $price,
                    'quantity' => (int) $quantity,
                    'listed_quantity' => (int) $quantity,
                    'quality' => (int) $quality,
                    'country' => (int) $countryId,
                    'status' => self::STATUS_OPEN,
                    'expires_at' => $expiresAt,
                ]);
                $orderId = (int) $order->id;
            }

            $inventory->quantity = (float) $inventory->quantity - (int) $quantity;
            if ($inventory->quantity <= 0) {
                $inventory->delete();
            } else {
                $inventory->save();
            }

            DB::commit();
            return ['order_id' => $orderId, 'expires_at' => $expiresAt, 'duration_hours' => $durationHours];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }
    }

    public static function purchase($id, $quantity, $uid)
    {
        if (!self::schemaAvailable() || $id < 1 || $quantity < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $notification = null;
        $capacityWarning = false;
        DB::beginTransaction();
        try {
            $offer = ItemOffer::with('country')->where('id', (int) $id)->lockForUpdate()->first();
            if (!$offer || $offer->status !== self::STATUS_OPEN || (int) $offer->quantity < (int) $quantity) {
                throw new AppException(AppException::INVALID_DATA);
            }

            if ($offer->expires_at && strtotime($offer->expires_at) <= time()) {
                self::returnAndCloseOffer($offer, self::STATUS_EXPIRED, 'expire');
                DB::commit();
                self::pushOrderNotification((int) $offer->uid, 'storage.notifications.order_expired.title', 'storage.notifications.order_expired.body');
                throw new AppException(AppException::INVALID_DATA);
            }

            if ((int) $offer->uid === (int) $uid) {
                throw new AppException(AppException::ACTION_FAILED);
            }

            DB::table('users')->where('id', (int) $uid)->lockForUpdate()->first();
            $buyerMoney = \App\Models\UserMoney::where(['uid' => (int) $uid])->lockForUpdate()->first();
            $sellerMoney = \App\Models\UserMoney::where(['uid' => (int) $offer->uid])->lockForUpdate()->first();
            $offerCountry = $offer->relationLoaded('country') ? $offer->getRelation('country') : $offer->country()->first();
            $currency = strtolower(trim((string) ($offerCountry->currency ?? '')));
            $cost = round((float) $offer->price * (int) $quantity, 2);

            if (!$buyerMoney || !$sellerMoney || $currency === '') {
                throw new AppException(AppException::ACTION_FAILED);
            }

            $buyerBalance = self::moneyBalance($buyerMoney, $currency);
            $sellerBalance = self::moneyBalance($sellerMoney, $currency);
            if ($buyerBalance === null || $sellerBalance === null) {
                throw new AppException(AppException::ACTION_FAILED);
            }
            if ($buyerBalance < $cost) {
                throw new AppException(AppException::NO_ENOUGH_MONEY);
            }

            $capacity = self::capacityForUser((int) $uid);
            $used = self::usedCapacity((int) $uid);
            if ($used + (int) $quantity > $capacity) {
                throw new AppException(AppException::NO_ENOUGH_RESOURCES);
            }

            $sellerMoney->setAttribute($currency, round($sellerBalance + $cost, 2));
            $sellerMoney->save();
            $buyerMoney->setAttribute($currency, round($buyerBalance - $cost, 2));
            $buyerMoney->save();

            $remaining = (int) $offer->quantity - (int) $quantity;
            $saleStatus = $remaining > 0 ? self::STATUS_PARTIAL : self::STATUS_COMPLETED;
            $offer->quantity = $remaining;
            $offer->status = $remaining > 0 ? self::STATUS_OPEN : self::STATUS_COMPLETED;
            if ($remaining <= 0) {
                $offer->closed_at = date('Y-m-d H:i:s');
            }
            $offer->save();

            $inventory = UserItem::firstOrNew([
                'uid' => (int) $uid,
                'item' => (int) $offer->item,
                'quality' => (int) $offer->quality,
            ]);
            $inventory->quantity = (float) ($inventory->quantity ?: 0) + (int) $quantity;
            $inventory->save();

            DB::table('market_order_events')->insert([
                'offer_id' => (int) $offer->id,
                'uid' => (int) $offer->uid,
                'item' => (int) $offer->item,
                'quality' => (int) $offer->quality,
                'country' => (int) $offer->country,
                'event_type' => 'sale',
                'status' => $saleStatus,
                'quantity' => (int) $quantity,
                'amount' => $cost,
                'currency' => strtoupper($currency),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            $capacityWarning = $capacity > 0 && (($used + (int) $quantity) / $capacity) >= 0.9;
            $notification = [$saleStatus, (int) $offer->uid, (int) $quantity, $cost, strtoupper($currency)];
            $result = [
                'offer_id' => (int) $id,
                'quantity' => (int) $quantity,
                'currency' => $currency,
                'cost' => $cost,
                'remaining' => $remaining,
                'status' => $saleStatus,
            ];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }

        if ($notification) {
            $key = $notification[0] === self::STATUS_COMPLETED ? 'storage.notifications.sale_completed' : 'storage.notifications.sale_partial';
            self::pushOrderNotification($notification[1], $key . '.title', $key . '.body', [
                'quantity' => $notification[2],
                'amount' => number_format($notification[3], 2, '.', ''),
                'currency' => $notification[4],
            ]);
        }
        if ($capacityWarning) {
            self::pushOrderNotification((int) $uid, 'storage.notifications.capacity_warning.title', 'storage.notifications.capacity_warning.body');
        }
        return $result;
    }

    public static function cancel($id, $uid)
    {
        if (!self::schemaAvailable() || (int) $id < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        DB::beginTransaction();
        try {
            $offer = ItemOffer::where('id', (int) $id)->lockForUpdate()->first();
            if (!$offer || (int) $offer->uid !== (int) $uid || $offer->status !== self::STATUS_OPEN || (int) $offer->quantity < 1) {
                throw new AppException(AppException::INVALID_DATA);
            }

            DB::table('users')->where('id', (int) $uid)->lockForUpdate()->first();
            $returnQuantity = (int) $offer->quantity;
            $inventory = UserItem::firstOrNew([
                'uid' => (int) $uid,
                'item' => (int) $offer->item,
                'quality' => (int) $offer->quality,
            ]);
            $inventory->quantity = (float) ($inventory->quantity ?: 0) + $returnQuantity;
            $inventory->save();

            $offer->quantity = 0;
            $offer->status = self::STATUS_CANCELLED;
            $offer->closed_at = date('Y-m-d H:i:s');
            $offer->save();

            DB::table('market_order_events')->insert([
                'offer_id' => (int) $offer->id,
                'uid' => (int) $uid,
                'item' => (int) $offer->item,
                'quality' => (int) $offer->quality,
                'country' => (int) $offer->country,
                'event_type' => 'cancel',
                'status' => self::STATUS_CANCELLED,
                'quantity' => $returnQuantity,
                'amount' => 0,
                'currency' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            DB::commit();
            self::pushOrderNotification((int) $uid, 'storage.notifications.order_cancelled.title', 'storage.notifications.order_cancelled.body', ['quantity' => $returnQuantity]);
            return ['order_id' => (int) $id, 'returned' => $returnQuantity];
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }
    }

    public static function expireDueOrders($uid = null)
    {
        if (!self::schemaAvailable()) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $query = ItemOffer::where('status', self::STATUS_OPEN)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
        if ($uid !== null) {
            $query->where('uid', (int) $uid);
        }

        $ids = $query->pluck('id');
        if (!$ids || count($ids) === 0) {
            return 0;
        }

        $notifications = [];
        $count = 0;
        DB::beginTransaction();
        try {
            foreach ($ids as $id) {
                $offer = ItemOffer::where('id', (int) $id)->lockForUpdate()->first();
                if (!$offer || $offer->status !== self::STATUS_OPEN || (int) $offer->quantity < 1 || !$offer->expires_at || strtotime($offer->expires_at) > time()) {
                    continue;
                }

                $quantity = (int) $offer->quantity;
                self::returnAndCloseOffer($offer, self::STATUS_EXPIRED, 'expire');
                $notifications[] = [(int) $offer->uid, $quantity];
                $count++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return 0;
        }

        foreach ($notifications as $notification) {
            self::pushOrderNotification($notification[0], 'storage.notifications.order_expired.title', 'storage.notifications.order_expired.body', ['quantity' => $notification[1]]);
        }
        return $count;
    }

    public static function usedCapacity($uid)
    {
        return (float) DB::table('user_items')->where('uid', (int) $uid)->sum('quantity');
    }

    public static function capacityForUser($uid)
    {
        $row = DB::table('user_storage_settings')->where('uid', (int) $uid)->first();
        if ($row) {
            return max(1, (int) $row->capacity);
        }

        $now = date('Y-m-d H:i:s');
        DB::table('user_storage_settings')->insert([
            'uid' => (int) $uid,
            'capacity' => self::DEFAULT_STORAGE_CAPACITY,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return self::DEFAULT_STORAGE_CAPACITY;
    }

    private static function returnAndCloseOffer(ItemOffer $offer, $status, $eventType)
    {
        $quantity = (int) $offer->quantity;
        if ($quantity > 0) {
            DB::table('users')->where('id', (int) $offer->uid)->lockForUpdate()->first();
            $inventory = UserItem::firstOrNew([
                'uid' => (int) $offer->uid,
                'item' => (int) $offer->item,
                'quality' => (int) $offer->quality,
            ]);
            $inventory->quantity = (float) ($inventory->quantity ?: 0) + $quantity;
            $inventory->save();
        }

        $offer->quantity = 0;
        $offer->status = $status;
        $offer->closed_at = date('Y-m-d H:i:s');
        $offer->save();

        DB::table('market_order_events')->insert([
            'offer_id' => (int) $offer->id,
            'uid' => (int) $offer->uid,
            'item' => (int) $offer->item,
            'quality' => (int) $offer->quality,
            'country' => (int) $offer->country,
            'event_type' => $eventType,
            'status' => $status,
            'quantity' => $quantity,
            'amount' => 0,
            'currency' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function moneyBalance($wallet, $currency)
    {
        $attributes = $wallet->getAttributes();
        if (!array_key_exists($currency, $attributes)) {
            return null;
        }
        return round((float) $wallet->getAttribute($currency), 2);
    }

    private static function pushOrderNotification($uid, $titleKey, $bodyKey, array $vars = [])
    {
        $title = function_exists('t') ? t($titleKey, $vars) : $titleKey;
        $body = function_exists('t') ? t($bodyKey, $vars) : $bodyKey;
        if ($title === $titleKey) {
            $title = 'Depo bildirimi';
        }
        if ($body === $bodyKey) {
            $body = 'Satış emri durumunda bir değişiklik oldu.';
        }
        Notify::push((int) $uid, 'storage_' . str_replace('.', '_', $titleKey), $title, $body, '/storage');
    }
}

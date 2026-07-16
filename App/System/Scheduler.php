<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;

final class Scheduler
{
    public static function run(): array
    {
        $result = [
            'queued' => 0,
            'fallback' => 0,
            'skipped' => 0,
        ];

        if (!Queue::isEnabled()) {
            $result['fallback'] = self::expireOrdersSynchronously();
            return $result;
        }

        $lockTtl = max(60, (int) (getenv('SCHEDULER_LOCK_TTL') ?: 120));
        if (Cache::isAvailable() && !Cache::add('scheduler:lock', 1, $lockTtl)) {
            $result['skipped']++;
            return $result;
        }

        try {
            $dedupe = 'market-expiry:' . date('YmdHi');
            if (Queue::dispatch('market.expire_due_orders', [], $dedupe, 120)) {
                $result['queued']++;
                return $result;
            }

            // Redis kapaliysa scheduler kullanici akislarini bekletmeden mevcut
            // transaction/idempotency kurallarini kullanan guvenli fallback'i calistirir.
            if (!Cache::isAvailable()) {
                $result['fallback'] = self::expireOrdersSynchronously();
            } else {
                $result['skipped']++;
            }
        } finally {
            // Dedupe anahtari ayni zaman penceresindeki tekrar dispatch'i engeller;
            // kilidi serbest birakmak sonraki scheduler turunu geciktirmez.
            if (Cache::isAvailable()) {
                Cache::forget('scheduler:lock');
            }
        }

        return $result;
    }

    private static function expireOrdersSynchronously(): int
    {
        try {
            return (int) MarketOrderService::expireDueOrders();
        } catch (\Throwable $e) {
            Logger::error('Scheduler fallback failed.', ['message' => $e->getMessage()]);
            return 0;
        }
    }
}

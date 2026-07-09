<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;

final class ActionRateLimiter
{
    public static function throttle($action, $scopeKey, $limit, $windowSeconds, $blockSeconds, $message)
    {
        if ($action === '' || $scopeKey === '' || (int) $limit < 1 || (int) $windowSeconds < 1) {
            return null;
        }

        try {
            $now = time();
            $nowSql = date('Y-m-d H:i:s', $now);
            $windowStartSql = date('Y-m-d H:i:s', $now - (int) $windowSeconds);

            $row = DB::table('auth_rate_limits')
                ->where('action', (string) $action)
                ->where('scope_key', (string) $scopeKey)
                ->first();

            if (!$row) {
                DB::table('auth_rate_limits')->insert([
                    'action' => (string) $action,
                    'scope_key' => (string) $scopeKey,
                    'attempts' => 1,
                    'window_started_at' => $nowSql,
                    'last_attempt_at' => $nowSql,
                    'blocked_until' => null,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);
                return null;
            }

            $blockedUntil = !empty($row->blocked_until) ? strtotime((string) $row->blocked_until) : false;
            if ($blockedUntil && $blockedUntil > $now) {
                return [
                    'error' => 1,
                    'code' => 'RATE_LIMIT',
                    'message' => $message,
                    'retry_after' => max(1, $blockedUntil - $now),
                ];
            }

            $windowStartedAt = !empty($row->window_started_at) ? strtotime((string) $row->window_started_at) : false;
            $attempts = (int) ($row->attempts ?? 0);

            if (!$windowStartedAt || $windowStartedAt < strtotime($windowStartSql)) {
                DB::table('auth_rate_limits')
                    ->where('id', (int) $row->id)
                    ->update([
                        'attempts' => 1,
                        'window_started_at' => $nowSql,
                        'last_attempt_at' => $nowSql,
                        'blocked_until' => null,
                        'updated_at' => $nowSql,
                    ]);
                return null;
            }

            $attempts++;
            $updateData = [
                'attempts' => $attempts,
                'last_attempt_at' => $nowSql,
                'updated_at' => $nowSql,
            ];

            if ($attempts > (int) $limit) {
                $updateData['blocked_until'] = date('Y-m-d H:i:s', $now + (int) $blockSeconds);
            }

            DB::table('auth_rate_limits')
                ->where('id', (int) $row->id)
                ->update($updateData);

            if ($attempts > (int) $limit) {
                return [
                    'error' => 1,
                    'code' => 'RATE_LIMIT',
                    'message' => $message,
                    'retry_after' => (int) $blockSeconds,
                ];
            }
        } catch (\Exception $e) {
            Logger::warning('Action rate limiter unavailable.', [
                'action' => $action,
                'scope_key' => $scopeKey,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }
}

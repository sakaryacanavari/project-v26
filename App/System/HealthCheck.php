<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;

final class HealthCheck
{
    public static function report(): array
    {
        $db = self::database();
        $redis = Cache::isAvailable();
        $runtime = self::runtime();
        $queue = self::worker();
        $scheduler = self::scheduler();
        $degraded = !$db || !$redis || !$runtime['ok']
            || in_array($queue['status'], ['unknown', 'stale', 'error', 'stopped'], true)
            || in_array($scheduler['status'], ['unknown', 'stale', 'error', 'stopped'], true);

        return [
            'status' => $degraded ? 'degraded' : 'ok',
            'checks' => [
                'database' => $db ? 'ok' : 'down',
                'redis' => $redis ? 'ok' : 'degraded',
                'runtime' => $runtime['ok'] ? 'ok' : 'degraded',
                'queue_worker' => $queue['status'],
                'scheduler' => $scheduler['status'],
            ],
            'details' => [
                'runtime' => $runtime,
                'queue_worker' => $queue,
                'scheduler' => $scheduler,
            ],
        ];
    }

    private static function database(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function runtime(): array
    {
        $paths = [APP_ROOT . 'tmp', APP_ROOT . 'tmp/logs'];
        $cachePath = APP_ROOT . 'tmp/cache';
        if (is_dir($cachePath) || @mkdir($cachePath, 0775, true)) {
            $paths[] = $cachePath;
        }
        $writable = true;
        foreach ($paths as $path) {
            if (!is_dir($path) || !is_writable($path)) {
                $writable = false;
            }
        }
        return ['ok' => $writable];
    }

    private static function worker(): array
    {
        if (!Queue::isEnabled()) {
            return ['status' => 'disabled'];
        }
        $data = RuntimeStatus::read('queue-worker');
        $updatedAt = strtotime((string) ($data['updated_at'] ?? ''));
        if ($updatedAt < 1) {
            return ['status' => 'unknown'];
        }
        return [
            'status' => in_array(($data['status'] ?? ''), ['error', 'stopped'], true)
                ? (string) $data['status']
                : ($updatedAt >= time() - 300 ? 'ok' : 'stale'),
            'last_run_at' => date('c', $updatedAt),
            'jobs_processed' => (int) ($data['jobs_processed'] ?? 0),
        ];
    }

    private static function scheduler(): array
    {
        $heartbeat = RuntimeStatus::read('scheduler');
        $cron = CronHealth::latest('market-expiry');
        if (!$heartbeat && !$cron) {
            return ['status' => 'unknown'];
        }
        $heartbeatAt = strtotime((string) ($heartbeat['updated_at'] ?? ''));
        $lastSuccessAt = strtotime((string) ($heartbeat['last_success_at'] ?? $cron['last_success_at'] ?? ''));
        if ($heartbeatAt < 1 || $lastSuccessAt < 1) {
            return ['status' => 'stale'];
        }
        $runtimeStatus = (string) ($heartbeat['status'] ?? '');
        return [
            'status' => in_array($runtimeStatus, ['error', 'stopped'], true)
                ? $runtimeStatus
                : (($heartbeatAt >= time() - 300 && $lastSuccessAt >= time() - 600) ? 'ok' : 'stale'),
            'runtime_status' => $runtimeStatus,
            'last_heartbeat_at' => date('c', $heartbeatAt),
            'last_success_at' => date('c', $lastSuccessAt),
            'processed' => (int) ($heartbeat['processed'] ?? 0),
        ];
    }
}

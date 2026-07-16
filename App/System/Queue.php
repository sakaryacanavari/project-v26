<?php

namespace App\System;

final class Queue
{
    const NAME = 'default';
    const MAX_ATTEMPTS = 3;

    public static function isEnabled(): bool
    {
        try {
            $settings = App::settings();
            if (isset($settings['queue']['enabled'])) {
                return (bool) $settings['queue']['enabled'];
            }
        } catch (\Throwable $e) {
        }

        return filter_var(getenv('QUEUE_ENABLED') ?: '1', FILTER_VALIDATE_BOOLEAN);
    }

    public static function dispatch($type, array $payload = [], $dedupeKey = null, $dedupeTtl = 86400): bool
    {
        if (!self::isEnabled() || !Cache::isAvailable()) {
            return false;
        }

        $jobId = bin2hex(random_bytes(12));
        if ($dedupeKey !== null) {
            $dedupeKey = 'queue:dedupe:' . trim((string) $dedupeKey);
            if (!Cache::add($dedupeKey, $jobId, $dedupeTtl)) {
                return false;
            }
        }

        $job = [
            'id' => $jobId,
            'type' => (string) $type,
            'payload' => $payload,
            'attempts' => 0,
            'queued_at' => date('c'),
        ];

        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || !Cache::listPush('queue:' . self::NAME, $encoded)) {
            if ($dedupeKey !== null) {
                Cache::forget($dedupeKey);
            }
            return false;
        }

        return true;
    }

    public static function pop($timeout = 5)
    {
        $raw = Cache::listPop('queue:' . self::NAME, $timeout);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $job = json_decode($raw, true);
        if (!is_array($job) || empty($job['id']) || empty($job['type'])) {
            Logger::warning('Invalid queue job discarded.', [
                'payload_size' => strlen($raw),
                'payload_hash' => sha1($raw),
            ]);
            return null;
        }

        return $job;
    }

    public static function requeue(array $job): bool
    {
        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && Cache::listPush('queue:' . self::NAME, $encoded);
    }

    public static function fail(array $job, $error): void
    {
        $job['failed_at'] = date('c');
        $job['error'] = substr((string) $error, 0, 1000);
        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            Cache::listPush('queue:failed', $encoded);
        }
        Logger::error('Queue job failed permanently.', [
            'job_id' => $job['id'] ?? '',
            'type' => $job['type'] ?? '',
            'attempts' => $job['attempts'] ?? 0,
            'message' => substr((string) $error, 0, 1000),
        ]);
    }

    public static function process(array $job, callable $handler): bool
    {
        $jobId = (string) ($job['id'] ?? '');
        if ($jobId === '') {
            return false;
        }

        $done = false;
        Cache::get('queue:done:' . $jobId, $done);
        if ($done) {
            return true;
        }

        if (!Cache::add('queue:processing:' . $jobId, 1, 300)) {
            // Başka bir worker aynı job'ı işliyorsa ikinci kez çalıştırma.
            return true;
        }

        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        $startedAt = microtime(true);
        Logger::info('Queue job started.', [
            'job_id' => $jobId,
            'type' => $job['type'],
            'attempt' => $job['attempts'],
        ]);

        try {
            $handler($job);
            Cache::put('queue:done:' . $jobId, 1, 604800);
            Cache::forget('queue:processing:' . $jobId);
            Logger::info('Queue job completed.', [
                'job_id' => $jobId,
                'type' => $job['type'],
                'attempt' => $job['attempts'],
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
            return true;
        } catch (\Throwable $e) {
            Cache::forget('queue:processing:' . $jobId);
            Logger::error('Queue job failed.', [
                'job_id' => $jobId,
                'type' => $job['type'],
                'attempt' => $job['attempts'],
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
            $maxAttempts = max(1, (int) (getenv('QUEUE_MAX_ATTEMPTS') ?: self::MAX_ATTEMPTS));
            try {
                $settings = App::settings();
                $maxAttempts = max(1, (int) ($settings['queue']['max_attempts'] ?? self::MAX_ATTEMPTS));
            } catch (\Throwable $ignored) {
            }

            if ($job['attempts'] < $maxAttempts) {
                $delay = min(15, 2 * $job['attempts']);
                Logger::warning('Queue job retry scheduled.', [
                    'job_id' => $job['id'] ?? '',
                    'type' => $job['type'] ?? '',
                    'attempts' => $job['attempts'],
                    'message' => $e->getMessage(),
                ]);
                sleep($delay);
                self::requeue($job);
            } else {
                self::fail($job, $e->getMessage());
            }
            return false;
        }
    }

    public static function retryFailed(): int
    {
        $count = 0;
        while ($raw = Cache::listPop('queue:failed')) {
            $job = json_decode($raw, true);
            if (!is_array($job) || empty($job['id']) || empty($job['type'])) {
                continue;
            }

            unset($job['failed_at'], $job['error']);
            $job['attempts'] = 0;
            if (self::requeue($job)) {
                $count++;
            }
        }

        return $count;
    }

    public static function heartbeat($jobsProcessed = 0, $status = 'running', array $extra = []): void
    {
        RuntimeStatus::write('queue-worker', array_merge([
            'status' => (string) $status,
            'jobs_processed' => (int) $jobsProcessed,
            'pid' => function_exists('getmypid') ? getmypid() : null,
        ], $extra));
    }
}

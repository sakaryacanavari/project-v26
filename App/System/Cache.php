<?php

namespace App\System;

/**
 * Small Redis cache adapter with a no-op fallback for local environments.
 * Critical state must continue to be read from and written to MySQL.
 */
final class Cache
{
    private const PREFIX = 'project_v26:';

    private static $redis = null;
    private static $attempted = false;
    private static $metrics = [
        'hit' => 0,
        'miss' => 0,
        'fallback' => 0,
        'errors' => 0,
        'connection_errors' => 0,
    ];

    public static function resetMetrics(): void
    {
        self::$metrics = ['hit' => 0, 'miss' => 0, 'fallback' => 0, 'errors' => 0, 'connection_errors' => 0];
    }

    public static function metrics(): array
    {
        return self::$metrics;
    }

    public static function userKey($uid, $name): string
    {
        return 'user:' . (int) $uid . ':' . trim((string) $name);
    }

    public static function isAvailable(): bool
    {
        if (self::$attempted) {
            return self::$redis instanceof \Redis;
        }

        self::$attempted = true;
        $config = self::config();

        if (empty($config['enabled']) || !class_exists('Redis')) {
            self::$metrics['fallback']++;
            return false;
        }

        try {
            $redis = new \Redis();
            if (!@$redis->connect($config['host'], (int) $config['port'], (float) $config['timeout'])) {
                self::$metrics['connection_errors']++;
                return false;
            }

            if ($config['password'] !== '' && !@$redis->auth($config['password'])) {
                return false;
            }

            if (!@$redis->select((int) $config['database'])) {
                return false;
            }

            $redis->setOption(\Redis::OPT_PREFIX, self::PREFIX);
            if (!@$redis->ping()) {
                return false;
            }

            self::$redis = $redis;
            return true;
        } catch (\Throwable $e) {
            self::$redis = null;
            self::$metrics['connection_errors']++;
            return false;
        }
    }

    public static function sessionSavePath(): string
    {
        $config = self::config();
        $query = [
            'database' => (int) $config['database'],
            'prefix' => self::PREFIX . 'session:',
        ];

        if ($config['password'] !== '') {
            $query['auth'] = $config['password'];
        }

        return 'tcp://' . $config['host'] . ':' . (int) $config['port'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function get($key, &$found = null)
    {
        $found = false;
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return null;
        }

        try {
            $raw = self::$redis->get((string) $key);
            if ($raw === false) {
                self::$metrics['miss']++;
                return null;
            }

            self::$metrics['hit']++;
            $found = true;
            return @unserialize($raw);
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
            return null;
        }
    }

    public static function put($key, $value, $ttl): bool
    {
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return false;
        }

        try {
            return (bool) self::$redis->setex((string) $key, max(1, (int) $ttl), serialize($value));
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
            return false;
        }
    }

    public static function remember($key, $ttl, callable $resolver)
    {
        $found = false;
        $cached = self::get($key, $found);
        if ($found) {
            return $cached;
        }

        $value = $resolver();
        self::put($key, $value, $ttl);
        return $value;
    }

    public static function forget($key): void
    {
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return;
        }

        try {
            self::$redis->del((string) $key);
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
        }
    }

    public static function add($key, $value, $ttl): bool
    {
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return false;
        }

        try {
            return (bool) self::$redis->set(
                (string) $key,
                serialize($value),
                ['nx', 'ex' => max(1, (int) $ttl)]
            );
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
            return false;
        }
    }

    public static function listPush($key, $value): bool
    {
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return false;
        }

        try {
            return (int) self::$redis->rPush((string) $key, (string) $value) > 0;
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
            return false;
        }
    }

    public static function listPop($key, $timeout = 0)
    {
        if (!self::isAvailable()) {
            self::$metrics['fallback']++;
            return null;
        }

        try {
            $timeout = max(0, (int) $timeout);
            if ($timeout > 0) {
                $result = self::$redis->blPop([(string) $key], $timeout);
                return is_array($result) && isset($result[1]) ? $result[1] : null;
            }

            $result = self::$redis->lPop((string) $key);
            return $result === false ? null : $result;
        } catch (\Throwable $e) {
            self::$metrics['errors']++;
            return null;
        }
    }

    private static function config(): array
    {
        $defaults = [
            'enabled' => false,
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 0.25,
        ];

        try {
            $settings = App::settings();
            if (isset($settings['redis']) && is_array($settings['redis'])) {
                return array_merge($defaults, $settings['redis']);
            }
        } catch (\Throwable $e) {
        }

        $defaults['enabled'] = filter_var(getenv('REDIS_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
        $defaults['host'] = getenv('REDIS_HOST') ?: $defaults['host'];
        $defaults['port'] = (int) (getenv('REDIS_PORT') ?: $defaults['port']);
        $defaults['password'] = (string) (getenv('REDIS_PASSWORD') ?: '');
        $defaults['database'] = (int) (getenv('REDIS_DB') ?: $defaults['database']);
        $defaults['timeout'] = (float) (getenv('REDIS_TIMEOUT') ?: $defaults['timeout']);
        return $defaults;
    }
}

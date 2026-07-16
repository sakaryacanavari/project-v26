<?php

namespace App\System;

final class Logger
{
    private static $logFile = null;
    private static $level = 'info';
    private static $maxBytes = 10485760;
    private static $maxFiles = 5;
    private static $dedupeSeconds = 60;
    private static $recent = [];

    public static function boot($logFile, array $options = [])
    {
        self::$logFile = $logFile;
        self::$level = self::normalizeLevel($options['level'] ?? 'info');
        self::$maxBytes = max(1024 * 1024, (int) ($options['max_bytes'] ?? 10485760));
        self::$maxFiles = max(1, (int) ($options['max_files'] ?? 5));
        self::$dedupeSeconds = max(0, (int) ($options['dedupe_seconds'] ?? 60));
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public static function info($message, array $context = [])
    {
        self::write('INFO', $message, $context);
    }

    public static function debug($message, array $context = [])
    {
        self::write('DEBUG', $message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::write('WARNING', $message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::write('ERROR', $message, $context);
    }

    public static function exception($exception, array $context = [])
    {
        if (!$exception instanceof \Throwable && !$exception instanceof \Exception) {
            self::error('Non-exception passed to logger.', $context);
            return;
        }

        $context['exception_class'] = get_class($exception);
        $context['file'] = $exception->getFile();
        $context['line'] = $exception->getLine();
        if (self::isDevelopment()) {
            $context['trace'] = $exception->getTraceAsString();
        }

        self::write('EXCEPTION', $exception->getMessage(), $context);
    }

    private static function write($level, $message, array $context = [])
    {
        if (empty(self::$logFile) || !self::allows($level)) {
            return;
        }

        $context = self::sanitize($context);
        $message = self::sanitizeString((string) $message);

        if (in_array($level, ['WARNING', 'ERROR', 'EXCEPTION'], true) && self::isDuplicate($level, $message, $context)) {
            return;
        }

        self::rotateIfNeeded();

        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];

        @file_put_contents(
            self::$logFile,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }

    private static function allows($level): bool
    {
        $weights = ['DEBUG' => 10, 'INFO' => 20, 'WARNING' => 30, 'ERROR' => 40, 'EXCEPTION' => 40];
        return ($weights[$level] ?? 40) >= ($weights[self::levelName()] ?? 20);
    }

    private static function levelName(): string
    {
        return strtoupper(self::$level);
    }

    private static function normalizeLevel($level): string
    {
        $level = strtolower(trim((string) $level));
        return in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info';
    }

    private static function isDevelopment(): bool
    {
        try {
            return (App::settings()['mode'] ?? '') === 'development';
        } catch (\Throwable $e) {
            return getenv('APP_ENV') === 'development';
        }
    }

    private static function sanitize($value, $key = '')
    {
        $sensitive = preg_match('/password|secret|token|csrf|cookie|session|authorization|body|content|email/i', $key);
        if ($sensitive) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[(string) $childKey] = self::sanitize($childValue, (string) $childKey);
            }
            return $clean;
        }
        if (is_object($value)) {
            return '[object ' . get_class($value) . ']';
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        return $value;
    }

    private static function sanitizeString($value): string
    {
        $value = preg_replace('/((?:password|secret|token|csrf|cookie|session[_ -]?id|authorization)\s*[=:]\s*)[^\s,;]+/i', '$1[redacted]', $value);
        $value = preg_replace("/'(?:''|[^'])*'/", "'[redacted]'", $value);
        $value = preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '[redacted]', $value);
        return mb_substr((string) $value, 0, 2000);
    }

    private static function isDuplicate($level, $message, array $context): bool
    {
        if (self::$dedupeSeconds <= 0) {
            return false;
        }
        $stableContext = $context;
        foreach (['request_id', 'duration_ms', 'uid', 'job_id', 'attempt'] as $volatileKey) {
            unset($stableContext[$volatileKey]);
        }
        $fingerprint = sha1($level . '|' . $message . '|' . json_encode($stableContext));
        $now = time();
        if (isset(self::$recent[$fingerprint]) && ($now - self::$recent[$fingerprint]) < self::$dedupeSeconds) {
            return true;
        }
        self::$recent[$fingerprint] = $now;
        if (count(self::$recent) > 500) {
            self::$recent = array_slice(self::$recent, -250, null, true);
        }
        return false;
    }

    private static function rotateIfNeeded(): void
    {
        if (!self::$logFile || !is_file(self::$logFile) || @filesize(self::$logFile) < self::$maxBytes) {
            return;
        }

        for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
            $source = self::$logFile . '.' . $i;
            $target = self::$logFile . '.' . ($i + 1);
            if (is_file($source)) {
                @rename($source, $target);
            }
        }
        @rename(self::$logFile, self::$logFile . '.1');
    }
}

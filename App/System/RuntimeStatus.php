<?php

namespace App\System;

final class RuntimeStatus
{
    public static function write($name, array $data = []): void
    {
        $path = self::path($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $data['updated_at'] = date('c');
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function read($name): array
    {
        $raw = @file_get_contents(self::path($name));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    private static function path($name): string
    {
        $safeName = preg_replace('/[^a-z0-9_-]/i', '', (string) $name);
        return APP_ROOT . 'tmp/runtime/' . $safeName . '.json';
    }
}

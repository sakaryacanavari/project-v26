<?php

namespace App\System;

/** Resolves optional Vite assets without making a missing build fatal. */
final class AssetManifest
{
    private static ?array $manifest = null;
    private static bool $loaded = false;

    public static function url(string $entry): ?string
    {
        $entry = ltrim(trim($entry), '/');
        if ($entry === '') {
            return null;
        }

        $devServer = trim((string) getenv('VITE_DEV_SERVER_URL'));
        if ($devServer !== '') {
            $devEntry = str_starts_with($entry, 'frontend/') ? $entry : 'frontend/' . $entry;
            return rtrim($devServer, '/') . '/' . $devEntry;
        }

        $manifest = self::load();
        if ($manifest === null) {
            return null;
        }

        foreach ([$entry, 'frontend/' . $entry] as $key) {
            $asset = $manifest[$key] ?? null;
            if (is_array($asset) && !empty($asset['file'])) {
                return '/public/build/' . ltrim((string) $asset['file'], '/');
            }
        }

        return null;
    }

    private static function load(): ?array
    {
        if (self::$loaded) {
            return self::$manifest;
        }

        self::$loaded = true;
        $path = defined('APP_ROOT') ? APP_ROOT . 'public/build/manifest.json' : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$manifest = is_array($decoded) ? $decoded : null;
        return self::$manifest;
    }
}

<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

final class GameExperience
{
    public static function getDefaultPreferences(): array
    {
        return [
            'ui_density' => 'balanced',
            'animation_level' => 'balanced',
            'left_hud' => 'detailed',
            'shout_width' => 'balanced',
            'home_layout' => 'wide',
            'home_priority' => 'news',
            'quick_favorites' => ['training', 'jobs', 'storage', 'market'],
        ];
    }

    public static function getQuickActions(): array
    {
        return [
            'training' => ['route' => 'gyms', 'icon' => 'fa-dumbbell', 'label_key' => 'home.quick.training'],
            'jobs' => ['route' => 'workOffers', 'icon' => 'fa-briefcase', 'label_key' => 'home.quick.jobs'],
            'storage' => ['route' => 'storage', 'icon' => 'fa-box-archive', 'label_key' => 'home.quick.storage'],
            'market' => ['route' => 'marketplace', 'icon' => 'fa-cart-shopping', 'label_key' => 'home.quick.market'],
            'parties' => ['route' => 'partyList', 'icon' => 'fa-landmark-dome', 'label_key' => 'home.quick.parties'],
            'messages' => ['route' => 'messages', 'icon' => 'fa-envelope', 'label_key' => 'home.quick.messages'],
            'map' => ['route' => 'worldMap', 'icon' => 'fa-map', 'label_key' => 'home.quick.map'],
            'settings' => ['route' => 'settings', 'icon' => 'fa-sliders', 'label_key' => 'home.quick.settings'],
        ];
    }

    public static function getPreferences(int $uid): array
    {
        $defaults = self::getDefaultPreferences();
        if ($uid < 1 || !self::ensureTable()) {
            return $defaults;
        }

        try {
            $row = DB::table('game_experience_settings')->where('uid', $uid)->first();
            if (!$row) {
                return $defaults;
            }

            return [
                'ui_density' => self::normalize((string) ($row->ui_density ?? ''), ['compact', 'balanced', 'comfortable'], $defaults['ui_density']),
                'animation_level' => self::normalize((string) ($row->animation_level ?? ''), ['off', 'balanced', 'full'], $defaults['animation_level']),
                'left_hud' => self::normalize((string) ($row->left_hud ?? ''), ['detailed', 'compact'], $defaults['left_hud']),
                'shout_width' => self::normalize((string) ($row->shout_width ?? ''), ['compact', 'balanced', 'wide'], $defaults['shout_width']),
                'home_layout' => self::normalize((string) ($row->home_layout ?? ''), ['wide', 'compact_columns'], $defaults['home_layout']),
                'home_priority' => self::normalize((string) ($row->home_priority ?? ''), ['news', 'shouts', 'tasks'], $defaults['home_priority']),
                'quick_favorites' => self::normalizeFavorites((string) ($row->quick_favorites ?? ''), $defaults['quick_favorites']),
            ];
        } catch (\Exception $e) {
            return $defaults;
        }
    }

    public static function savePreferences(int $uid, array $preferences): bool
    {
        if ($uid < 1 || !self::ensureTable()) {
            return false;
        }

        $normalized = self::normalizePreferences($preferences);
        $now = date('Y-m-d H:i:s');
        $payload = [
            'uid' => $uid,
            'ui_density' => $normalized['ui_density'],
            'animation_level' => $normalized['animation_level'],
            'left_hud' => $normalized['left_hud'],
            'shout_width' => $normalized['shout_width'],
            'home_layout' => $normalized['home_layout'],
            'home_priority' => $normalized['home_priority'],
            'quick_favorites' => json_encode($normalized['quick_favorites'], JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        try {
            $exists = DB::table('game_experience_settings')->where('uid', $uid)->exists();
            if ($exists) {
                $written = DB::table('game_experience_settings')->where('uid', $uid)->update($payload) !== false;
            } else {
                $payload['created_at'] = $now;
                $written = (bool) DB::table('game_experience_settings')->insert($payload);
            }

            if (!$written) {
                return false;
            }

            $saved = self::getPreferences($uid);
            return $saved['ui_density'] === $normalized['ui_density']
                && $saved['animation_level'] === $normalized['animation_level']
                && $saved['left_hud'] === $normalized['left_hud']
                && $saved['shout_width'] === $normalized['shout_width']
                && $saved['home_layout'] === $normalized['home_layout']
                && $saved['home_priority'] === $normalized['home_priority']
                && $saved['quick_favorites'] === $normalized['quick_favorites'];
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function normalizePreferences(array $preferences): array
    {
        $defaults = self::getDefaultPreferences();
        $favorites = isset($preferences['quick_favorites']) && is_array($preferences['quick_favorites'])
            ? $preferences['quick_favorites']
            : $defaults['quick_favorites'];

        return [
            'ui_density' => self::normalize((string) ($preferences['ui_density'] ?? ''), ['compact', 'balanced', 'comfortable'], $defaults['ui_density']),
            'animation_level' => self::normalize((string) ($preferences['animation_level'] ?? ''), ['off', 'balanced', 'full'], $defaults['animation_level']),
            'left_hud' => self::normalize((string) ($preferences['left_hud'] ?? ''), ['detailed', 'compact'], $defaults['left_hud']),
            'shout_width' => self::normalize((string) ($preferences['shout_width'] ?? ''), ['compact', 'balanced', 'wide'], $defaults['shout_width']),
            'home_layout' => self::normalize((string) ($preferences['home_layout'] ?? ''), ['wide', 'compact_columns'], $defaults['home_layout']),
            'home_priority' => self::normalize((string) ($preferences['home_priority'] ?? ''), ['news', 'shouts', 'tasks'], $defaults['home_priority']),
            'quick_favorites' => self::normalizeFavoritesArray($favorites),
        ];
    }

    public static function buildQuickLinks(array $preferences, callable $pathFor): array
    {
        $actions = self::getQuickActions();
        $favorites = self::normalizeFavoritesArray($preferences['quick_favorites'] ?? []);
        $orderedKeys = array_values(array_unique(array_merge($favorites, array_keys($actions))));
        $links = [];

        foreach ($orderedKeys as $key) {
            if (!isset($actions[$key])) {
                continue;
            }

            $links[] = [
                'key' => $key,
                'href' => $pathFor($actions[$key]['route']),
                'icon' => $actions[$key]['icon'],
                'label_key' => $actions[$key]['label_key'],
                'is_favorite' => in_array($key, $favorites, true),
            ];
        }

        return $links;
    }

    private static function ensureTable(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();
            if ($schema->hasTable('game_experience_settings')) {
                $schema->table('game_experience_settings', function (Blueprint $table) use ($schema) {
                    if (!$schema->hasColumn('game_experience_settings', 'ui_density')) {
                        $table->string('ui_density', 20)->default('balanced');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'animation_level')) {
                        $table->string('animation_level', 20)->default('balanced');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'left_hud')) {
                        $table->string('left_hud', 20)->default('detailed');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'shout_width')) {
                        $table->string('shout_width', 20)->default('balanced');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'home_layout')) {
                        $table->string('home_layout', 30)->default('wide');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'home_priority')) {
                        $table->string('home_priority', 20)->default('news');
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'quick_favorites')) {
                        $table->text('quick_favorites')->nullable();
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'created_at')) {
                        $table->dateTime('created_at')->nullable();
                    }
                    if (!$schema->hasColumn('game_experience_settings', 'updated_at')) {
                        $table->dateTime('updated_at')->nullable();
                    }
                });
                return true;
            }

            $schema->create('game_experience_settings', function (Blueprint $table) {
                $table->unsignedInteger('uid')->primary();
                $table->string('ui_density', 20)->default('balanced');
                $table->string('animation_level', 20)->default('balanced');
                $table->string('left_hud', 20)->default('detailed');
                $table->string('shout_width', 20)->default('balanced');
                $table->string('home_layout', 30)->default('wide');
                $table->string('home_priority', 20)->default('news');
                $table->text('quick_favorites')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function normalize(string $value, array $allowed, string $fallback): string
    {
        $value = trim($value);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function normalizeFavorites(string $json, array $fallback): array
    {
        $decoded = json_decode($json, true);
        return self::normalizeFavoritesArray(is_array($decoded) ? $decoded : $fallback);
    }

    private static function normalizeFavoritesArray(array $favorites): array
    {
        $valid = array_keys(self::getQuickActions());
        $clean = [];

        foreach ($favorites as $favorite) {
            $favorite = trim((string) $favorite);
            if (in_array($favorite, $valid, true) && !in_array($favorite, $clean, true)) {
                $clean[] = $favorite;
            }
            if (count($clean) >= 4) {
                break;
            }
        }

        return $clean;
    }
}

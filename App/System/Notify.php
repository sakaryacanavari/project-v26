<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

final class Notify
{
    public static function getDefaultPreferences(): array
    {
        return [
            'dm_enabled' => 1,
            'news_enabled' => 1,
            'system_enabled' => 1,
            'quiet_hours_enabled' => 0,
            'quiet_start' => '22:00',
            'quiet_end' => '08:00',
        ];
    }

    public static function ensurePreferencesTable(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();
            if ($schema->hasTable('notification_preferences')) {
                return true;
            }

            $schema->create('notification_preferences', function (Blueprint $table) {
                $table->unsignedInteger('uid')->primary();
                $table->tinyInteger('dm_enabled')->default(1);
                $table->tinyInteger('news_enabled')->default(1);
                $table->tinyInteger('system_enabled')->default(1);
                $table->tinyInteger('quiet_hours_enabled')->default(0);
                $table->string('quiet_start', 5)->default('22:00');
                $table->string('quiet_end', 5)->default('08:00');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getPreferences(int $uid): array
    {
        $defaults = self::getDefaultPreferences();
        if ($uid < 1 || !self::ensurePreferencesTable()) {
            return $defaults;
        }

        try {
            $row = DB::table('notification_preferences')->where('uid', $uid)->first();
            if (!$row) {
                return $defaults;
            }

            return [
                'dm_enabled' => (int)($row->dm_enabled ?? $defaults['dm_enabled']),
                'news_enabled' => (int)($row->news_enabled ?? $defaults['news_enabled']),
                'system_enabled' => (int)($row->system_enabled ?? $defaults['system_enabled']),
                'quiet_hours_enabled' => (int)($row->quiet_hours_enabled ?? $defaults['quiet_hours_enabled']),
                'quiet_start' => self::normalizeTime((string)($row->quiet_start ?? $defaults['quiet_start']), $defaults['quiet_start']),
                'quiet_end' => self::normalizeTime((string)($row->quiet_end ?? $defaults['quiet_end']), $defaults['quiet_end']),
            ];
        } catch (\Exception $e) {
            return $defaults;
        }
    }

    public static function savePreferences(int $uid, array $preferences): bool
    {
        if ($uid < 1 || !self::ensurePreferencesTable()) {
            return false;
        }

        $defaults = self::getDefaultPreferences();
        $now = date('Y-m-d H:i:s');
        $payload = [
            'uid' => $uid,
            'dm_enabled' => !empty($preferences['dm_enabled']) ? 1 : 0,
            'news_enabled' => !empty($preferences['news_enabled']) ? 1 : 0,
            'system_enabled' => !empty($preferences['system_enabled']) ? 1 : 0,
            'quiet_hours_enabled' => !empty($preferences['quiet_hours_enabled']) ? 1 : 0,
            'quiet_start' => self::normalizeTime((string)($preferences['quiet_start'] ?? $defaults['quiet_start']), $defaults['quiet_start']),
            'quiet_end' => self::normalizeTime((string)($preferences['quiet_end'] ?? $defaults['quiet_end']), $defaults['quiet_end']),
            'updated_at' => $now,
        ];

        try {
            $exists = DB::table('notification_preferences')->where('uid', $uid)->exists();
            if ($exists) {
                return (bool) DB::table('notification_preferences')->where('uid', $uid)->update($payload);
            }

            $payload['created_at'] = $now;
            return (bool) DB::table('notification_preferences')->insert($payload);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * V7.0 OMNI-COMMAND Bildirim Motoru
     * Not: Ana işlemleri (Mesaj atma, savaşma vb.) çökertmemesi için try-catch zırhı ile donatıldı.
     */
    public static function push(
        int $uid,
        string $type,
        string $title,
        string $body = '',
        ?string $url = null,
        array $meta = []
    ): bool {
        try {
            $now = date('Y-m-d H:i:s');
            $preferences = self::getPreferences($uid);
            $isCritical = self::isCriticalType($type);
            $category = self::resolveCategory($type);

            if (!$isCritical && !self::isCategoryEnabled($category, $preferences)) {
                return false;
            }

            $isRead = (!$isCritical && !empty($preferences['quiet_hours_enabled']) && self::isQuietHours($preferences)) ? 1 : 0;

            $inserted = DB::table('notifications')->insert([
                'uid'        => $uid,
                'type'       => $type,
                'title'      => $title,
                'body'       => $body !== '' ? $body : null,
                'url'        => $url,
                'meta'       => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'is_read'    => $isRead,
                'created_at' => $now,
            ]);

            return $inserted;
            
        } catch (\Exception $e) {
            // Eğer bildirim tablosunda bir sorun varsa sistemi ÇÖKERTME, sadece bildirimi es geç.
            // Geliştirici logu: error_log("BİLDİRİM HATASI: " . $e->getMessage());
            return false;
        }
    }

    private static function normalizeTime(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $fallback;
    }

    private static function resolveCategory(string $type): string
    {
        if ($type === 'dm') {
            return 'dm';
        }

        if ($type === 'newspaper_article') {
            return 'news';
        }

        if (preg_match('/^(admin_|system_|state_decree|shout_deleted|shout_restriction)/', $type)) {
            return 'system';
        }

        return 'other';
    }

    private static function isCriticalType(string $type): bool
    {
        return in_array($type, ['admin_critical_article', 'system_critical', 'critical_system', 'country_critical_shout'], true)
            || (bool) preg_match('/critical|kritik/i', $type);
    }

    private static function isCategoryEnabled(string $category, array $preferences): bool
    {
        if ($category === 'dm') {
            return !empty($preferences['dm_enabled']);
        }

        if ($category === 'news') {
            return !empty($preferences['news_enabled']);
        }

        if ($category === 'system') {
            return !empty($preferences['system_enabled']);
        }

        return true;
    }

    private static function isQuietHours(array $preferences): bool
    {
        $start = self::timeToMinutes((string)($preferences['quiet_start'] ?? '22:00'));
        $end = self::timeToMinutes((string)($preferences['quiet_end'] ?? '08:00'));
        $now = ((int)date('H')) * 60 + (int)date('i');

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $now >= $start && $now < $end;
        }

        return $now >= $start || $now < $end;
    }

    private static function timeToMinutes(string $time): int
    {
        $time = self::normalizeTime($time, '00:00');
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return $hour * 60 + $minute;
    }
}

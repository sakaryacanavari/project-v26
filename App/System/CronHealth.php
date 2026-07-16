<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;

final class CronHealth
{
    private static function hasTable()
    {
        try {
            return DB::getSchemaBuilder()->hasTable('system_cron_status');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function start($name)
    {
        if (!self::hasTable()) {
            return;
        }

        DB::table('system_cron_status')->updateOrInsert(
            ['name' => (string) $name],
            [
                'last_started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public static function success($name, array $meta = [])
    {
        if (!self::hasTable()) {
            return;
        }

        DB::table('system_cron_status')->updateOrInsert(
            ['name' => (string) $name],
            [
                'last_started_at' => date('Y-m-d H:i:s'),
                'last_success_at' => date('Y-m-d H:i:s'),
                'last_error_at' => null,
                'last_error_message' => null,
                'last_meta_json' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public static function failure($name, $exception)
    {
        $message = $exception instanceof \Throwable ? $exception->getMessage() : (string) $exception;

        Logger::error('Cron failed: ' . $name, [
            'cron' => (string) $name,
            'message' => $message,
        ]);

        if (!self::hasTable()) {
            return;
        }

        DB::table('system_cron_status')->updateOrInsert(
            ['name' => (string) $name],
            [
                'last_started_at' => date('Y-m-d H:i:s'),
                'last_error_at' => date('Y-m-d H:i:s'),
                'last_error_message' => mb_substr($message, 0, 1000),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public static function latest($name): array
    {
        if (!self::hasTable()) {
            return [];
        }
        try {
            $row = DB::table('system_cron_status')->where('name', (string) $name)->first();
            return $row ? (array) $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

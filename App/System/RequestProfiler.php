<?php

namespace App\System;

final class RequestProfiler
{
    private static $logFile = null;

    public static function boot($logFile)
    {
        self::$logFile = $logFile;
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public static function log(array $payload)
    {
        if (empty(self::$logFile)) {
            return;
        }

        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'request_profile',
            'payload' => $payload,
        ];

        @file_put_contents(
            self::$logFile,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}

<?php

namespace App\System;

final class Logger
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

    public static function info($message, array $context = [])
    {
        self::write('INFO', $message, $context);
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
        $context['trace'] = $exception->getTraceAsString();

        self::write('EXCEPTION', $exception->getMessage(), $context);
    }

    private static function write($level, $message, array $context = [])
    {
        if (empty(self::$logFile)) {
            return;
        }

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
}

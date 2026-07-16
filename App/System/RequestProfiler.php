<?php

namespace App\System;

final class RequestProfiler
{
    private static $logFile = null;
    private static $requestId = null;
    private static $startedAt = 0.0;
    private static $queryCount = 0;
    private static $queryTimeMs = 0.0;
    private static $slowQueries = 0;
    private static $slowRequestMs = 1000.0;
    private static $slowQueryMs = 250.0;
    private static $listenerAttached = false;
    private static $connection = null;
    private static $request = null;

    public static function boot($logFile, array $options = [])
    {
        self::$logFile = $logFile;
        self::$slowRequestMs = max(1, (float) ($options['slow_request_ms'] ?? 1000));
        self::$slowQueryMs = max(1, (float) ($options['slow_query_ms'] ?? 250));
        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public static function start($request, $connection = null): void
    {
        self::$requestId = bin2hex(random_bytes(8));
        self::$request = $request;
        self::$startedAt = microtime(true);
        self::$queryCount = 0;
        self::$queryTimeMs = 0.0;
        self::$slowQueries = 0;
        register_shutdown_function(function () {
            if (self::$startedAt) {
                self::finish(null, null, 0);
            }
        });
        self::$connection = $connection;
        if ($connection && method_exists($connection, 'flushQueryLog') && method_exists($connection, 'enableQueryLog')) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }
        if ($connection && !self::$listenerAttached && method_exists($connection, 'listen')) {
            $connection->listen(function ($query) {
                if (!self::$startedAt) {
                    return;
                }
                self::$queryCount++;
                self::$queryTimeMs += (float) ($query->time ?? 0);
                if ((float) ($query->time ?? 0) >= self::$slowQueryMs) {
                    self::$slowQueries++;
                    Logger::warning('Slow database query.', [
                        'request_id' => self::$requestId,
                        'duration_ms' => round((float) $query->time, 2),
                        'sql' => self::safeSql((string) ($query->sql ?? '')),
                    ]);
                }
            });
            self::$listenerAttached = true;
        }
    }

    public static function finish($request, $response, $uid = 0)
    {
        if (!self::$startedAt) {
            return $response;
        }
        if ($request === null) {
            $request = self::$request;
        }
        if (self::$connection && self::$queryCount === 0 && method_exists(self::$connection, 'getQueryLog')) {
            $queryLog = self::$connection->getQueryLog();
            if (is_array($queryLog)) {
                foreach ($queryLog as $query) {
                    self::$queryCount++;
                    self::$queryTimeMs += (float) ($query['time'] ?? 0);
                    if ((float) ($query['time'] ?? 0) >= self::$slowQueryMs) {
                        self::$slowQueries++;
                        Logger::warning('Slow database query.', [
                            'request_id' => self::$requestId,
                            'duration_ms' => round((float) ($query['time'] ?? 0), 2),
                            'sql' => self::safeSql((string) ($query['query'] ?? '')),
                        ]);
                    }
                }
            }
        }
        $durationMs = self::$startedAt ? round((microtime(true) - self::$startedAt) * 1000, 2) : 0.0;
        $route = '';
        $routeObject = is_object($request) && method_exists($request, 'getAttribute') ? $request->getAttribute('route') : null;
        if (is_object($routeObject) && method_exists($routeObject, 'getName')) {
            $route = (string) $routeObject->getName();
        }
        $path = is_object($request) && method_exists($request, 'getUri') ? self::safePath($request->getUri()->getPath()) : '';
        $status = is_object($response) && method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : (int) http_response_code();
        if ($status < 100) {
            $status = 500;
        }
        $payload = [
            'request_id' => self::$requestId,
            'method' => is_object($request) && method_exists($request, 'getMethod') ? $request->getMethod() : '',
            'route' => $route !== '' ? $route : $path,
            'status' => $status,
            'uid' => (int) $uid,
            'duration_ms' => $durationMs,
            'queries' => self::$queryCount,
            'query_time_ms' => round(self::$queryTimeMs, 2),
            'slow_queries' => self::$slowQueries,
            'cache' => Cache::metrics(),
        ];
        self::log($payload);
        if ($durationMs >= self::$slowRequestMs) {
            Logger::warning('Slow HTTP request.', [
                'request_id' => self::$requestId,
                'route' => $route !== '' ? $route : $path,
                'status' => $status,
                'uid' => (int) $uid,
                'duration_ms' => $durationMs,
                'queries' => self::$queryCount,
            ]);
        }
        if (is_object($response) && method_exists($response, 'withHeader')) {
            $response = $response->withHeader('X-Request-Id', (string) self::$requestId);
        }
        self::$startedAt = 0.0;
        self::$connection = null;
        self::$request = null;
        return $response;
    }

    public static function currentRequestId(): string
    {
        return (string) self::$requestId;
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

        self::rotateIfNeeded();
        @file_put_contents(
            self::$logFile,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }

    private static function safeSql($sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/", "'?'", (string) $sql);
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
        return mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 500);
    }

    private static function safePath($path): string
    {
        return preg_replace('#/(reset-password|verify-email)/[^/]+#i', '/$1/[redacted]', (string) $path);
    }

    private static function rotateIfNeeded(): void
    {
        if (!self::$logFile || !is_file(self::$logFile) || @filesize(self::$logFile) < 10485760) {
            return;
        }
        for ($i = 4; $i >= 1; $i--) {
            if (is_file(self::$logFile . '.' . $i)) {
                @rename(self::$logFile . '.' . $i, self::$logFile . '.' . ($i + 1));
            }
        }
        @rename(self::$logFile, self::$logFile . '.1');
    }
}

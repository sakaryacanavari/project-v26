<?php

require dirname(__DIR__) . '/bootstrap.php';

$baseUrl = rtrim((string) (getenv('APP_URL') ?: 'http://localhost:8080'), '/');
$cookieFile = null;
foreach ($argv as $index => $argument) {
    if ($argument === '--base-url' && isset($argv[$index + 1])) {
        $baseUrl = rtrim((string) $argv[$index + 1], '/');
    }
    if ($argument === '--cookie-file' && isset($argv[$index + 1])) {
        $cookieFile = (string) $argv[$index + 1];
    }
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required for this benchmark.\n");
    exit(1);
}

$paths = ['/' => 'dashboard', '/gyms' => 'antrenman', '/storage' => 'depo', '/messages' => 'mesajlar'];
$profilePath = dirname(__DIR__) . '/tmp/logs/profile.log';

foreach ($paths as $path => $label) {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($cookieFile !== null && is_file($cookieFile)) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $startedAt = microtime(true);
    $raw = curl_exec($ch);
    $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = is_string($raw) ? substr($raw, 0, $headerSize) : '';
    curl_close($ch);

    $requestId = '';
    if (preg_match('/^X-Request-Id:\s*([^\r\n]+)/mi', $headers, $match)) {
        $requestId = trim($match[1]);
    }
    $profile = null;
    if ($requestId !== '' && is_file($profilePath)) {
        $lines = file($profilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $record = json_decode($lines[$i], true);
            if (($record['payload']['request_id'] ?? '') === $requestId) {
                $profile = $record['payload'];
                break;
            }
        }
    }
    if ($profile === null && is_file($profilePath)) {
        $lines = file($profilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $record = json_decode($lines[$i], true);
            if (($record['payload']['route'] ?? '') === $path) {
                $profile = $record['payload'];
                break;
            }
        }
    }

    printf("%-12s status=%d elapsed_ms=%s queries=%s query_time_ms=%s\n", $label, $status, $elapsedMs, $profile['queries'] ?? 'n/a', $profile['query_time_ms'] ?? 'n/a');
}

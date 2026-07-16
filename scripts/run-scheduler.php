<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\System\Scheduler;
use App\System\CronHealth;
use App\System\Logger;
use App\System\RuntimeStatus;

$loop = in_array('--loop', $argv, true);
$interval = max(10, (int) (getenv('SCHEDULER_INTERVAL') ?: 60));
$stopRequested = false;
$exitCode = 0;
$lastSuccessAt = RuntimeStatus::read('scheduler')['last_success_at'] ?? null;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stopRequested): void {
        $stopRequested = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$runOnce = static function () use (&$lastSuccessAt): bool {
    $startedAt = microtime(true);
    RuntimeStatus::write('scheduler', [
        'status' => 'running',
        'task' => 'market-expiry',
        'pid' => function_exists('getmypid') ? getmypid() : null,
    ]);

    try {
        CronHealth::start('market-expiry');
        Logger::info('Scheduler run started.', ['task' => 'market-expiry']);
        $result = Scheduler::run();
        $processed = (int) (($result['queued'] ?? 0) + ($result['fallback'] ?? 0));
        $lastSuccessAt = date('c');
        CronHealth::success('market-expiry', $result);
        RuntimeStatus::write('scheduler', [
            'status' => 'success',
            'task' => 'market-expiry',
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'processed' => $processed,
            'last_success_at' => $lastSuccessAt,
        ]);
        Logger::info('Scheduler run completed.', [
            'task' => 'market-expiry',
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'processed' => $processed,
        ]);
        fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return true;
    } catch (Throwable $e) {
        CronHealth::failure('market-expiry', $e);
        RuntimeStatus::write('scheduler', [
            'status' => 'error',
            'task' => 'market-expiry',
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'last_success_at' => $lastSuccessAt,
        ]);
        Logger::error('Scheduler run failed.', [
            'task' => 'market-expiry',
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
        fwrite(STDERR, "Scheduler failed.\n");
        return false;
    }
};

do {
    if (!$runOnce()) {
        $exitCode = 1;
    }

    if (!$loop || $stopRequested) {
        break;
    }

    for ($remaining = $interval; $remaining > 0 && !$stopRequested; $remaining--) {
        sleep(1);
    }
} while (!$stopRequested);

RuntimeStatus::write('scheduler', [
    'status' => 'stopped',
    'task' => 'market-expiry',
    'pid' => function_exists('getmypid') ? getmypid() : null,
    'last_success_at' => $lastSuccessAt,
    'stopped_reason' => $stopRequested ? 'signal' : 'completed',
]);

exit($loop ? 0 : $exitCode);

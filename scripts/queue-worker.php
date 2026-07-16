<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\System\Queue;
use App\System\MarketOrderService;

$once = in_array('--once', $argv, true);
$processed = 0;
$maxJobs = max(0, (int) (getenv('QUEUE_WORKER_MAX_JOBS') ?: 500));
$maxRuntime = max(0, (int) (getenv('QUEUE_WORKER_MAX_RUNTIME') ?: 3600));
$startedAt = microtime(true);
$stopRequested = false;
$exitCode = 0;
$finalStatus = 'stopped';

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stopRequested): void {
        $stopRequested = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

Queue::heartbeat(0, 'running', ['max_jobs' => $maxJobs, 'max_runtime' => $maxRuntime]);

try {
    if (in_array('--retry-failed', $argv, true)) {
        echo Queue::retryFailed() . PHP_EOL;
    } else {
        do {
            if ($stopRequested || ($maxJobs > 0 && $processed >= $maxJobs)
                || ($maxRuntime > 0 && microtime(true) - $startedAt >= $maxRuntime)) {
                break;
            }

            $job = Queue::pop(5);
            if (!$job) {
                Queue::heartbeat($processed, 'running');
                continue;
            }

            Queue::process($job, function (array $job) {
                switch ((string) $job['type']) {
                    case 'market.expire_due_orders':
                        MarketOrderService::expireDueOrders();
                        return;
                    default:
                        throw new RuntimeException('Unknown queue job type.');
                }
            });
            $processed++;
            Queue::heartbeat($processed, 'running');
        } while (!$once);
    }
} catch (Throwable $e) {
    $finalStatus = 'error';
    $exitCode = 1;
    \App\System\Logger::exception($e, ['command' => 'queue-worker']);
    fwrite(STDERR, "Queue worker failed.\n");
} finally {
    Queue::heartbeat($processed, $finalStatus, [
        'stopped_reason' => $stopRequested ? 'signal' : (($maxJobs > 0 && $processed >= $maxJobs) ? 'max_jobs' : (($maxRuntime > 0 && microtime(true) - $startedAt >= $maxRuntime) ? 'max_runtime' : 'completed')),
    ]);
}

exit($exitCode);

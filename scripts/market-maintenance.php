<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\System\MarketOrderService;
use App\System\Logger;
use App\System\SchemaMigrations;

set_exception_handler(function (Throwable $e) {
    Logger::exception($e, ['command' => 'market-maintenance']);
    fwrite(STDERR, "Market maintenance failed.\n");
    exit(1);
});

$migrate = in_array('--migrate', $argv, true);
$expire = in_array('--expire', $argv, true);

if (!$migrate && !$expire) {
    fwrite(STDERR, "Usage: php scripts/market-maintenance.php --migrate [--expire]\n");
    fwrite(STDERR, "       php scripts/market-maintenance.php --expire\n");
    exit(2);
}

if ($migrate) {
    Logger::info('Market maintenance migration started.');
    SchemaMigrations::run(['market']);
    fwrite(STDOUT, "Market schema and indexes are ready.\n");
    Logger::info('Market maintenance migration completed.');
}

if ($expire) {
    $startedAt = microtime(true);
    Logger::info('Market expiry maintenance started.');
    if (!MarketOrderService::schemaAvailable()) {
        fwrite(STDERR, "Market schema is unavailable. Run --migrate first.\n");
        exit(1);
    }

    $count = MarketOrderService::expireDueOrders();
    fwrite(STDOUT, 'Expired orders processed: ' . (int) $count . PHP_EOL);
    Logger::info('Market expiry maintenance completed.', [
        'processed' => (int) $count,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    ]);
}

<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\System\Logger;
use App\System\SchemaMigrations;

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php scripts/schema-migrate.php [--market-only]\n");
    exit(0);
}

$scopes = in_array('--market-only', $argv, true) ? ['market'] : [];

try {
    $startedAt = microtime(true);
    $result = SchemaMigrations::run($scopes);
    $result['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);

    Logger::info('Schema migration completed.', $result);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    Logger::exception($e, ['command' => 'schema-migrate']);
    fwrite(STDERR, "Schema migration failed. Run it again after fixing the reported schema prerequisite.\n");
    exit(1);
}

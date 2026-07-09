<?php

require "../bootstrap.php";

use App\Controllers\Congress;
use App\System\App;
use App\System\CronHealth;

CronHealth::start('presidentialElections');

try {
    $controller = new Congress(App::getInstance(), null);
    $result = $controller->runScheduledElectionMaintenance();

    CronHealth::success('presidentialElections', [
        'schedule' => 'daily',
        'presidential_finalized' => (int) ($result['presidential_finalized'] ?? 0),
        'party_finalized' => (int) ($result['party_finalized'] ?? 0),
        'congress_finalized' => (int) ($result['congress_finalized'] ?? 0),
    ]);
} catch (\Throwable $e) {
    CronHealth::failure('presidentialElections', $e);
    throw $e;
}

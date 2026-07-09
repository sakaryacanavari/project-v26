<?php

require "../bootstrap.php";

use App\Models\Chat;
use App\System\CronHealth;

/**
 * THIS CRONJOB DELETES OLD CHAT MESSAGES
 *
 * RUN IT DAILY
 */

$aWeekAgo = date("Y-m-d H:i:s", strtotime("-1 week"));

CronHealth::start('deleteOldChats');

try {
    $deleted = Chat::where("created_at", "<", $aWeekAgo)->delete();
    CronHealth::success('deleteOldChats', ['deleted' => (int) $deleted, 'schedule' => 'daily']);
} catch (\Throwable $e) {
    CronHealth::failure('deleteOldChats', $e);
    throw $e;
}

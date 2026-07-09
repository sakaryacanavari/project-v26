<?php

require "../bootstrap.php";

use App\Controllers\Congress;
use App\Models\LawProposal;
use App\System\CronHealth;

/**
 * THIS CRON FINISHES LAW PROPOSALS
 * RUN IT DAILY
 */

$deadline = date("Y-m-d H:i:s", strtotime("-1 day"));

CronHealth::start('lawProposals');

try {
    $proposals = LawProposal::where("finished", 0)
        ->where("created_at", "<=", $deadline)
        ->get();

    $congress = new Congress();
    $finished = 0;
    $applied = 0;

    foreach ($proposals as $proposal) {
        if ((int) $proposal->yes > (int) $proposal->no) {
            try {
                $congress->applyLaw($proposal->id);
                $applied++;
            } catch (\Exception $e) {
            }
        }

        $proposal->finished = 1;
        $proposal->save();
        $finished++;
    }

    CronHealth::success('lawProposals', [
        'schedule' => 'daily',
        'finished' => $finished,
        'applied' => $applied,
    ]);
} catch (\Throwable $e) {
    CronHealth::failure('lawProposals', $e);
    throw $e;
}

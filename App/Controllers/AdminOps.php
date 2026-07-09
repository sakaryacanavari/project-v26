<?php

namespace App\Controllers;

use App\Models\PoliticalParty;
use App\Models\User;
use App\System\Controller;
use App\System\Notify;
use Illuminate\Database\Capsule\Manager as DB;

class AdminOps extends Controller
{
    private function getBetaHealthSummary(array $cronEntries, array $stats)
    {
        $healthyCrons = 0;
        $staleCrons = 0;
        $errorCrons = 0;

        foreach ($cronEntries as $cronEntry) {
            if (!empty($cronEntry['has_error'])) {
                $errorCrons++;
                continue;
            }

            if (!empty($cronEntry['stale'])) {
                $staleCrons++;
                continue;
            }

            $healthyCrons++;
        }

        $blockers = [];
        if ($errorCrons > 0) {
            $blockers[] = 'Cron hatasi bulunan is var.';
        }
        if ($staleCrons > 0) {
            $blockers[] = 'Geciken cron isleri var.';
        }
        if ((int) ($stats['gym_sync_pending'] ?? 0) > 0) {
            $blockers[] = 'Gym senkron bekleyen kullanicilar var.';
        }
        if ((int) ($stats['broken_party_applications'] ?? 0) > 0) {
            $blockers[] = 'Bozuk parti basvurulari temizlenmeli.';
        }
        if ((int) ($stats['dual_message_system'] ?? 0) > 0) {
            $blockers[] = 'Eski ve yeni mesaj sistemi birlikte aktif.';
        }

        return [
            'healthy_crons' => $healthyCrons,
            'stale_crons' => $staleCrons,
            'error_crons' => $errorCrons,
            'blockers' => $blockers,
            'ready' => empty($blockers),
            'smoke_doc_path' => APP_ROOT . 'EARLY_ACCESS_SMOKE_CHECKLIST.md',
            'runbook_doc_path' => APP_ROOT . 'BETA_RELEASE_RUNBOOK.md',
            'schema_doc_path' => APP_ROOT . 'SCHEMA_SYNC_APPLY_ORDER.md',
        ];
    }

    private function isAdminUser($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return false;
        }

        return (int) DB::table('users')->where('id', $uid)->value('is_admin') === 1;
    }

    private function hasShoutRestrictionsTable()
    {
        return DB::getSchemaBuilder()->hasTable('shout_user_restrictions');
    }

    private function getReportedShoutEntries()
    {
        if (!DB::getSchemaBuilder()->hasTable('shouts') || !DB::getSchemaBuilder()->hasTable('shout_reports')) {
            return [];
        }

        try {
            $rows = DB::table('shouts as s')
                ->leftJoin('users as u', 'u.id', '=', 's.uid')
                ->leftJoin('regions as ur', 'ur.id', '=', 'u.region')
                ->leftJoin('shout_user_restrictions as sur', 'sur.uid', '=', 's.uid')
                ->where('s.reports_count', '>', 0)
                ->orderBy('s.is_deleted', 'desc')
                ->orderBy('s.reports_count', 'desc')
                ->orderBy('s.id', 'desc')
                ->limit(12)
                ->get([
                    's.id',
                    's.uid',
                    's.body',
                    's.likes_count',
                    's.reports_count',
                    's.created_at',
                    's.is_deleted',
                    'u.nick',
                    DB::raw('COALESCE(u.country_id, ur.country, 0) as author_country_id'),
                    'sur.muted_until',
                ]);

            if ($rows->isEmpty()) {
                return [];
            }

            $shoutIds = $rows->pluck('id')->map(function ($id) {
                return (int) $id;
            })->toArray();

            $reportRows = DB::table('shout_reports as sr')
                ->leftJoin('users as ru', 'ru.id', '=', 'sr.uid')
                ->leftJoin('regions as rr', 'rr.id', '=', 'ru.region')
                ->whereIn('sr.shout_id', $shoutIds)
                ->orderBy('sr.created_at', 'desc')
                ->orderBy('sr.id', 'desc')
                ->get([
                    'sr.shout_id',
                    'sr.uid as reporter_uid',
                    'sr.reason',
                    'sr.created_at',
                    'ru.nick as reporter_nick',
                    DB::raw('COALESCE(ru.country_id, rr.country, 0) as reporter_country_id'),
                ]);

            $reportMap = [];
            foreach ($reportRows as $reportRow) {
                $reportShoutId = (int) ($reportRow->shout_id ?? 0);
                if ($reportShoutId < 1 || isset($reportMap[$reportShoutId])) {
                    continue;
                }

                $reportMap[$reportShoutId] = [
                    'latest_reason' => trim((string) ($reportRow->reason ?? '')),
                    'latest_reported_at' => (string) ($reportRow->created_at ?? ''),
                    'latest_reporter_uid' => (int) ($reportRow->reporter_uid ?? 0),
                    'latest_reporter_nick' => (string) ($reportRow->reporter_nick ?? ''),
                    'latest_reporter_country_id' => (int) ($reportRow->reporter_country_id ?? 0),
                ];
            }

            $entries = [];
            foreach ($rows as $row) {
                $shoutId = (int) ($row->id ?? 0);
                $reportMeta = $reportMap[$shoutId] ?? [];

                $entries[] = (object) [
                    'id' => $shoutId,
                    'uid' => (int) ($row->uid ?? 0),
                    'body' => (string) ($row->body ?? ''),
                    'likes_count' => (int) ($row->likes_count ?? 0),
                    'reports_count' => (int) ($row->reports_count ?? 0),
                    'created_at' => (string) ($row->created_at ?? ''),
                    'is_deleted' => (int) ($row->is_deleted ?? 0) === 1,
                    'nick' => (string) ($row->nick ?? ''),
                    'author_country_id' => (int) ($row->author_country_id ?? 0),
                    'muted_until' => $row->muted_until ?? null,
                    'latest_reason' => (string) ($reportMeta['latest_reason'] ?? ''),
                    'latest_reported_at' => (string) ($reportMeta['latest_reported_at'] ?? ''),
                    'latest_reporter_uid' => (int) ($reportMeta['latest_reporter_uid'] ?? 0),
                    'latest_reporter_nick' => (string) ($reportMeta['latest_reporter_nick'] ?? ''),
                    'latest_reporter_country_id' => (int) ($reportMeta['latest_reporter_country_id'] ?? 0),
                ];
            }

            return $entries;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function hasCronStatusTable()
    {
        return DB::getSchemaBuilder()->hasTable('system_cron_status');
    }

    private function getCronEntries()
    {
        $cronEntries = [];
        $cronFiles = glob(APP_ROOT . 'crons/*.php') ?: [];
        $cronNow = time();
        $statusMap = [];

        if ($this->hasCronStatusTable()) {
            $statusRows = DB::table('system_cron_status')->get();
            foreach ($statusRows as $statusRow) {
                $statusMap[(string) $statusRow->name] = $statusRow;
            }
        }

        foreach ($cronFiles as $cronFile) {
            $name = pathinfo($cronFile, PATHINFO_FILENAME);
            $updatedAt = filemtime($cronFile);
            $statusRow = $statusMap[$name] ?? null;
            $lastSuccessAt = $statusRow->last_success_at ?? null;
            $lastErrorAt = $statusRow->last_error_at ?? null;
            $lastStartedAt = $statusRow->last_started_at ?? null;
            $displayTime = $lastSuccessAt ?: ($lastErrorAt ?: ($lastStartedAt ?: ($updatedAt ? date('Y-m-d H:i:s', $updatedAt) : null)));

            $ageHours = null;
            if (!empty($displayTime)) {
                $ageHours = (int) floor(max(0, (time() - strtotime((string) $displayTime))) / 3600);
            } elseif ($updatedAt) {
                $ageHours = (int) floor(($cronNow - $updatedAt) / 3600);
            }

            $hasError = !empty($lastErrorAt) && (empty($lastSuccessAt) || strtotime((string) $lastErrorAt) >= strtotime((string) $lastSuccessAt));
            $isStale = $ageHours !== null ? ($ageHours >= 48) : true;

            $cronEntries[] = [
                'name' => $name,
                'updated_at' => $displayTime,
                'size' => (int) filesize($cronFile),
                'stale' => $isStale,
                'age_hours' => $ageHours,
                'has_error' => $hasError,
                'last_started_at' => $lastStartedAt,
                'last_success_at' => $lastSuccessAt,
                'last_error_at' => $lastErrorAt,
                'last_error_message' => $statusRow->last_error_message ?? null,
                'last_meta_json' => $statusRow->last_meta_json ?? null,
            ];
        }

        usort($cronEntries, function ($a, $b) {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        return $cronEntries;
    }

    private function getGymSyncPendingCount()
    {
        if (!DB::getSchemaBuilder()->hasTable('user_trainings')) {
            return 0;
        }

        $rows = DB::table('user_trainings')
            ->select('uid', 'quality', DB::raw('MAX(created_at) as trained_at'))
            ->groupBy('uid', 'quality')
            ->get();

        $pending = 0;
        foreach ($rows as $row) {
            $quality = (int) ($row->quality ?? 0);
            if ($quality < 1 || $quality > 4) {
                continue;
            }

            $gymRow = DB::table('user_gyms')->where('uid', (int) $row->uid)->first();
            $field = 'q' . $quality;
            $currentValue = $gymRow ? (string) ($gymRow->{$field} ?? '') : '';
            $targetValue = date('Y-m-d', strtotime((string) $row->trained_at));

            if ($currentValue !== $targetValue) {
                $pending++;
            }
        }

        return $pending;
    }

    private function getBrokenPartyApplicationCount()
    {
        if (!DB::getSchemaBuilder()->hasTable('party_join_applications')) {
            return 0;
        }

        return (int) DB::table('party_join_applications as app')
            ->join('party_members as pm', 'pm.uid', '=', 'app.uid')
            ->where('app.status', 'pending')
            ->count();
    }

    private function getDualMessageSystemState()
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('messages') || !$schema->hasTable('private_messages')) {
            return false;
        }

        return (int) DB::table('private_messages')->count() > 0;
    }

    public function index()
    {
        $cronEntries = $this->getCronEntries();

        $stats = [
            'open_work_offers' => (int) DB::table('work_offers')->whereNull('worker')->count(),
            'active_ads' => (int) DB::table('political_parties')->whereNotNull('ad_until')->where('ad_until', '>', date('Y-m-d H:i:s'))->count(),
            'pending_party_applications' => (int) DB::table('party_join_applications')->where('status', 'pending')->count(),
            'recent_notifications' => (int) DB::table('notifications')->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 day')))->count(),
            'active_wars' => (int) DB::table('wars')->where('status', 'active')->count(),
            'today_articles' => (int) DB::table('newspaper_articles')->whereRaw('DATE(created_at) = ?', [date('Y-m-d')])->count(),
            'party_count' => (int) DB::table('political_parties')->count(),
            'newspaper_count' => (int) DB::table('newspapers')->count(),
            'gym_sync_pending' => $this->getGymSyncPendingCount(),
            'broken_party_applications' => $this->getBrokenPartyApplicationCount(),
            'dual_message_system' => $this->getDualMessageSystemState() ? 1 : 0,
            'total_shouts' => DB::getSchemaBuilder()->hasTable('shouts') ? (int) DB::table('shouts')->where('is_deleted', 0)->count() : 0,
            'reported_shouts' => DB::getSchemaBuilder()->hasTable('shout_reports') ? (int) DB::table('shout_reports')->count() : 0,
            'muted_shout_users' => $this->hasShoutRestrictionsTable() ? (int) DB::table('shout_user_restrictions')->where('muted_until', '>', date('Y-m-d H:i:s'))->count() : 0,
        ];

        $betaHealth = $this->getBetaHealthSummary($cronEntries, $stats);

        $recentUsers = DB::table('users')
            ->orderBy('id', 'desc')
            ->limit(8)
            ->get(['id', 'nick', 'email', 'status', 'created_at'])
            ->toArray();

        $recentArticles = [];
        try {
            $recentArticles = DB::table('newspaper_articles as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                ->orderBy('a.id', 'desc')
                ->limit(6)
                ->get(['a.id', 'a.title', 'u.nick', 'a.created_at'])
                ->toArray();
        } catch (\Exception $e) {
            $recentArticles = [];
        }

        $recentShouts = $this->getReportedShoutEntries();

        $activeShoutRestrictions = [];
        if ($this->hasShoutRestrictionsTable()) {
            try {
                $activeShoutRestrictions = DB::table('shout_user_restrictions as sur')
                    ->leftJoin('users as u', 'u.id', '=', 'sur.uid')
                    ->whereNotNull('sur.muted_until')
                    ->where('sur.muted_until', '>', date('Y-m-d H:i:s'))
                    ->orderBy('sur.muted_until', 'asc')
                    ->limit(12)
                    ->get([
                        'sur.uid',
                        'sur.reason',
                        'sur.muted_until',
                        'sur.updated_at',
                        'u.nick',
                    ])
                    ->toArray();
            } catch (\Exception $e) {
                $activeShoutRestrictions = [];
            }
        }

        return $this->render('admin/ops.html.twig', [
            'cronEntries' => $cronEntries,
            'stats' => $stats,
            'betaHealth' => $betaHealth,
            'recentUsers' => $recentUsers,
            'recentArticles' => $recentArticles,
            'recentShouts' => $recentShouts,
            'activeShoutRestrictions' => $activeShoutRestrictions,
            'appLogPath' => APP_ROOT . 'tmp/logs/app.log',
        ]);
    }

    public function updateUserStatus()
    {
        $uid = (int) ($_POST['uid'] ?? 0);
        $status = (int) ($_POST['status'] ?? -1);

        if ($uid < 1 || !in_array($status, [User::STATUS_PENDING, User::STATUS_ACTIVATED, User::STATUS_BANNED], true)) {
            return ['error' => true, 'message' => 'Gecersiz kullanici veya durum secildi.'];
        }

        $updated = DB::table('users')
            ->where('id', $uid)
            ->update(['status' => $status]);

        if (!$updated) {
            return ['error' => true, 'message' => 'Kullanici durumu guncellenemedi.'];
        }

        return ['error' => false, 'message' => 'Kullanici durumu guncellendi.'];
    }

    public function cancelPartyAd()
    {
        $partyId = (int) ($_POST['party_id'] ?? 0);

        if ($partyId < 1) {
            return ['error' => true, 'message' => 'Gecersiz parti secildi.'];
        }

        $updated = DB::table('political_parties')
            ->where('id', $partyId)
            ->update([
                'ad_until' => null,
                'last_ad_purchase_at' => null,
            ]);

        if (!$updated) {
            return ['error' => true, 'message' => 'Parti reklami kapatilamadi.'];
        }

        return ['error' => false, 'message' => 'Parti reklami sifirlandi.'];
    }

    public function cancelWorkOffer()
    {
        $offerId = (int) ($_POST['offer_id'] ?? 0);

        if ($offerId < 1) {
            return ['error' => true, 'message' => 'Gecersiz is ilani secildi.'];
        }

        $updated = DB::table('work_offers')
            ->where('id', $offerId)
            ->whereNull('worker')
            ->delete();

        if (!$updated) {
            return ['error' => true, 'message' => 'Bos is ilani bulunamadi veya kaldirilamadi.'];
        }

        return ['error' => false, 'message' => 'Is ilani kaldirildi.'];
    }

    public function repairPartyCoalitions()
    {
        $repaired = DB::update(
            'UPDATE political_parties p
             JOIN coalitions c ON c.founder_party_id = p.id
             SET p.coalition_id = c.id
             WHERE p.coalition_id IS NULL'
        );

        return ['error' => false, 'message' => 'Koalisyon baglari onarildi.', 'repaired' => (int) $repaired];
    }

    public function syncUserGyms()
    {
        if (!DB::getSchemaBuilder()->hasTable('user_trainings')) {
            return ['error' => true, 'message' => 'user_trainings tablosu bulunamadi.'];
        }

        $rows = DB::table('user_trainings')
            ->select('uid', 'quality', DB::raw('MAX(created_at) as trained_at'))
            ->groupBy('uid', 'quality')
            ->get();

        $updatedUsers = [];

        foreach ($rows as $row) {
            $uid = (int) ($row->uid ?? 0);
            $quality = (int) ($row->quality ?? 0);
            if ($uid < 1 || $quality < 1 || $quality > 4) {
                continue;
            }

            $field = 'q' . $quality;
            $dateValue = date('Y-m-d', strtotime((string) $row->trained_at));

            $existing = DB::table('user_gyms')->where('uid', $uid)->first();
            if ($existing) {
                DB::table('user_gyms')->where('uid', $uid)->update([$field => $dateValue]);
            } else {
                DB::table('user_gyms')->insert([
                    'uid' => $uid,
                    'q1' => $quality === 1 ? $dateValue : null,
                    'q2' => $quality === 2 ? $dateValue : null,
                    'q3' => $quality === 3 ? $dateValue : null,
                    'q4' => $quality === 4 ? $dateValue : null,
                ]);
            }

            $updatedUsers[$uid] = true;
        }

        return [
            'error' => false,
            'message' => 'Gym gecmisi user_gyms tablosuna senkronlandi.',
            'updated_users' => count($updatedUsers)
        ];
    }

    public function repairPartyApplications()
    {
        if (!DB::getSchemaBuilder()->hasTable('party_join_applications')) {
            return ['error' => true, 'message' => 'party_join_applications tablosu bulunamadi.'];
        }

        $fixedMembershipConflicts = DB::update(
            "UPDATE party_join_applications app
             JOIN party_members pm ON pm.uid = app.uid
             SET app.status = 'rejected',
                 app.reviewed_at = NOW(),
                 app.updated_at = NOW()
             WHERE app.status = 'pending'"
        );

        $pendingRows = DB::table('party_join_applications')
            ->where('status', 'pending')
            ->orderBy('id', 'asc')
            ->get(['id', 'uid']);

        $keptByUser = [];
        $duplicateRejected = 0;

        foreach ($pendingRows as $pendingRow) {
            $uid = (int) $pendingRow->uid;
            if (!isset($keptByUser[$uid])) {
                $keptByUser[$uid] = (int) $pendingRow->id;
                continue;
            }

            DB::table('party_join_applications')
                ->where('id', (int) $pendingRow->id)
                ->update([
                    'status' => 'rejected',
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $duplicateRejected++;
        }

        return [
            'error' => false,
            'message' => 'Bozuk veya cift parti basvurulari temizlendi.',
            'membership_conflicts' => (int) $fixedMembershipConflicts,
            'duplicates' => (int) $duplicateRejected,
        ];
    }

    public function deleteArticle()
    {
        $articleId = (int) ($_POST['article_id'] ?? 0);

        if ($articleId < 1) {
            return ['error' => true, 'message' => 'Gecersiz makale ID secildi.'];
        }

        $article = DB::table('newspaper_articles')->where('id', $articleId)->first();
        if (!$article) {
            return ['error' => true, 'message' => 'Makale bulunamadi.'];
        }

        DB::beginTransaction();

        try {
            $commentIds = DB::table('article_comments')
                ->where('article_id', $articleId)
                ->pluck('id')
                ->toArray();

            if (!empty($commentIds)) {
                DB::table('article_comment_reactions')->whereIn('comment_id', $commentIds)->delete();
                DB::table('article_comment_votes')->whereIn('comment_id', $commentIds)->delete();
            }

            DB::table('article_comments')->where('article_id', $articleId)->delete();
            DB::table('article_endorsements')->where('article_id', $articleId)->delete();
            DB::table('article_fact_checks')->where('article_id', $articleId)->delete();
            DB::table('article_votes')->where('article', $articleId)->delete();
            DB::table('newspaper_articles')->where('id', $articleId)->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return ['error' => true, 'message' => 'Makale silinemedi: ' . $e->getMessage()];
        }

        return ['error' => false, 'message' => 'Makale ve bagli kayitlari kaldirildi.'];
    }

    public function deleteShout()
    {
        $shoutId = (int) ($_POST['shout_id'] ?? 0);

        if ($shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout ID secildi.'];
        }

        if (!DB::getSchemaBuilder()->hasTable('shouts')) {
            return ['error' => true, 'message' => 'Shout sistemi kurulu degil.'];
        }

        $shout = DB::table('shouts')->where('id', $shoutId)->first(['id', 'uid', 'body']);
        if (!$shout) {
            return ['error' => true, 'message' => 'Shout bulunamadi.'];
        }

        $updated = DB::table('shouts')
            ->where('id', $shoutId)
            ->update([
                'is_deleted' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if (!$updated) {
            return ['error' => true, 'message' => 'Shout kaldirilamadi.'];
        }

        if ($this->hasShoutRestrictionsTable()) {
            $deletedCount = (int) DB::table('shouts')
                ->where('uid', (int) $shout->uid)
                ->where('is_deleted', 1)
                ->where('updated_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->count();

            if ($deletedCount >= 3 && !$this->isAdminUser((int) $shout->uid)) {
                $mutedUntil = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $existing = DB::table('shout_user_restrictions')->where('uid', (int) $shout->uid)->first();

                if ($existing) {
                    DB::table('shout_user_restrictions')
                        ->where('uid', (int) $shout->uid)
                        ->update([
                            'muted_until' => $mutedUntil,
                            'reason' => 'Cok fazla kaldirilan shout davranisi',
                            'created_by' => null,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                } else {
                    DB::table('shout_user_restrictions')->insert([
                        'uid' => (int) $shout->uid,
                        'muted_until' => $mutedUntil,
                        'reason' => 'Cok fazla kaldirilan shout davranisi',
                        'created_by' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        Notify::push(
            (int) $shout->uid,
            'shout_deleted',
            'Bir shoutun kaldirildi',
            'Admin bir shoutunu kaldirdi.',
            $this->app->getContainer()->get('router')->pathFor('home'),
            ['shout_id' => $shoutId]
        );

        return ['error' => false, 'message' => 'Shout gizlendi.'];
    }

    public function reopenShout()
    {
        $shoutId = (int) ($_POST['shout_id'] ?? 0);

        if ($shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout ID secildi.'];
        }

        if (!DB::getSchemaBuilder()->hasTable('shouts')) {
            return ['error' => true, 'message' => 'Shout sistemi kurulu degil.'];
        }

        $updated = DB::table('shouts')
            ->where('id', $shoutId)
            ->where('is_deleted', 1)
            ->update([
                'is_deleted' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if (!$updated) {
            return ['error' => true, 'message' => 'Geri acilacak gizli shout bulunamadi.'];
        }

        return ['error' => false, 'message' => 'Shout tekrar gorunur hale getirildi.'];
    }

    public function muteShoutUser()
    {
        $uid = (int) ($_POST['uid'] ?? 0);

        if ($uid < 1) {
            return ['error' => true, 'message' => 'Gecersiz kullanici secildi.'];
        }

        if (!$this->hasShoutRestrictionsTable()) {
            return ['error' => true, 'message' => 'Shout kisit tablosu bulunamadi.'];
        }

        $mutedUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $adminUid = (int) $this->container->get('session')->getUid();

        try {
            $existing = DB::table('shout_user_restrictions')->where('uid', $uid)->first();

            if ($existing) {
                DB::table('shout_user_restrictions')
                    ->where('uid', $uid)
                    ->update([
                        'muted_until' => $mutedUntil,
                        'reason' => 'Admin temporary shout mute',
                        'created_by' => $adminUid,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                DB::table('shout_user_restrictions')->insert([
                    'uid' => $uid,
                    'muted_until' => $mutedUntil,
                    'reason' => 'Admin temporary shout mute',
                    'created_by' => $adminUid,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Shout kisiti uygulanamadi.'];
        }

        Notify::push(
            $uid,
            'shout_restriction',
            'Shout paylasimin gecici olarak kisitlandi',
            'Admin seni 24 saat boyunca shouttan susturdu.',
            $this->app->getContainer()->get('router')->pathFor('home'),
            ['muted_until' => $mutedUntil]
        );

        return ['error' => false, 'message' => 'Kullanici 24 saat shouttan susturuldu.'];
    }

    public function clearShoutRestriction()
    {
        $uid = (int) ($_POST['uid'] ?? 0);

        if ($uid < 1) {
            return ['error' => true, 'message' => 'Gecersiz kullanici secildi.'];
        }

        if (!$this->hasShoutRestrictionsTable()) {
            return ['error' => true, 'message' => 'Shout kisit tablosu bulunamadi.'];
        }

        $deleted = DB::table('shout_user_restrictions')
            ->where('uid', $uid)
            ->delete();

        if (!$deleted) {
            return ['error' => true, 'message' => 'Aktif bir shout kisiti bulunamadi.'];
        }

        Notify::push(
            $uid,
            'shout_restriction_lifted',
            'Shout kisitin kaldirildi',
            'Admin shout kisitini kaldirdi. Tekrar shout atabilirsin.',
            $this->app->getContainer()->get('router')->pathFor('home'),
            []
        );

        return ['error' => false, 'message' => 'Shout kisiti kaldirildi.'];
    }
}

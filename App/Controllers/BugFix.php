<?php

namespace App\Controllers;

use App\System\App;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class BugFix extends Controller
{
    private const LIMIT_WINDOW_MIN = 5;   // 5 dk
    private const LIMIT_MAX_IN_WINDOW = 3;
    private const LIMIT_MAX_PER_DAY = 20;
    private const MIN_SECONDS_BEFORE_SUBMIT = 4;

    private const CAT_ALLOWED = ['economy','war','politics','ui','performance','security','other'];
    private const PRI_ALLOWED = ['minor','major','critical'];
    private const REPRO_ALLOWED = ['once','sometimes','always'];

    /**
     * UI: Kullanıcının raporlarını listeler + stats + top issues
     */
    public function index()
    {
        $session = App::session();
        $session->ensureLogged();

        $userId = (int) $session->getUid();
        $csrf = $this->ensureCsrfToken();

        // Kullanıcının raporları ve ilişkileri
        $reports = DB::table('bug_reports')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($report) use ($userId) {
                $report->replies = DB::table('bug_replies')
                    ->where('report_id', $report->id)
                    ->orderBy('created_at', 'asc')
                    ->get();

                $report->is_voted = DB::table('bug_votes')
                    ->where('report_id', $report->id)
                    ->where('user_id', $userId)
                    ->exists();

                $report->is_subscribed = DB::table('bug_subscriptions')
                    ->where('report_id', $report->id)
                    ->where('user_id', $userId)
                    ->exists();

                $report->votes_count = (int)($report->votes_count ?? 0);

                return $report;
            });

        // İstatistikler
        $myTotal = DB::table('bug_reports')->where('user_id', $userId)->count();
        $myVotesSum = (int) DB::table('bug_reports')->where('user_id', $userId)->sum('votes_count');
        $myVotedCount = DB::table('bug_votes')->where('user_id', $userId)->count();
        $mySubCount = DB::table('bug_subscriptions')->where('user_id', $userId)->count();

        // Top Issues
        $topIssues = DB::table('bug_reports')
            ->orderBy('votes_count', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(7)
            ->get(['id','title','status','votes_count','category','created_at'])
            ->map(function ($r) {
                $r->votes_count = (int)($r->votes_count ?? 0);
                return $r;
            });

        // İŞTE ÇÖZÜM BURADA: Sistemin kendi orijinal "render" metodunu kullanıyoruz.
        // Bu metod, "my" objesini (Altın, enerji, güç vb.) Twig'e OTOMATİK olarak yollar.
        return $this->render('bugreports/bugfix.html.twig', [
            'reports'    => $reports,
            'csrf_token' => $csrf,
            'csrf'       => $csrf,
            'stats'      => [
                'my_total'     => (int)$myTotal,
                'my_votes_sum' => (int)$myVotesSum,
                'my_voted'     => (int)$myVotedCount,
                'my_subs'      => (int)$mySubCount,
            ],
            'topIssues'  => $topIssues
        ]);
    }

    /**
     * API: Yeni rapor oluşturur
     */
    public function create()
    {
        $session = App::session();
        $session->ensureLogged();
        $userId = (int) $session->getUid();

        if (!$this->validateCsrf($_POST['csrf_token'] ?? '')) {
            return ['error' => true, 'code' => 'CSRF', 'message' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.'];
        }

        if (!empty(trim($_POST['contact_me_by_fax_only'] ?? '')) || !empty(trim($_POST['website'] ?? ''))) {
            return ['error' => true, 'code' => 'SPAM', 'message' => 'Spam algılandı.'];
        }

        $clientStartedAt = $this->parseClientTimestampToUnixSeconds($_POST['form_started_at'] ?? 0);
        if ($clientStartedAt > 0 && (time() - $clientStartedAt) < self::MIN_SECONDS_BEFORE_SUBMIT) {
            return ['error' => true, 'code' => 'FAST_SUBMIT', 'message' => 'Çok hızlı gönderim algılandı. Birkaç saniye bekleyip tekrar deneyin.'];
        }

        $category     = in_array($_POST['category'] ?? '', self::CAT_ALLOWED, true) ? $_POST['category'] : 'other';
        $priority     = in_array($_POST['priority'] ?? '', self::PRI_ALLOWED, true) ? $_POST['priority'] : 'major';
        $reproRate    = in_array($_POST['repro_rate'] ?? '', self::REPRO_ALLOWED, true) ? $_POST['repro_rate'] : 'sometimes';
        
        $title        = trim($_POST['title'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $imageUrl     = trim($_POST['image_url'] ?? '');
        $errorCode    = trim($_POST['error_code'] ?? '');

        if ($title === '' || $description === '') {
            return ['error' => true, 'message' => 'Başlık ve açıklama zorunludur.'];
        }

        if ($this->looksMalicious($title) || $this->looksMalicious($description)) {
            return ['error' => true, 'code' => 'CONTENT_BLOCKED', 'message' => 'İçerik güvenlik filtresine takıldı.'];
        }

        if ($imageUrl !== '' && !$this->isSafeHttpUrl($imageUrl)) {
            $imageUrl = '';
        }

        $rl = $this->checkRateLimit($userId);
        if ($rl['blocked']) {
            return ['error' => true, 'code' => 'RATE_LIMIT', 'message' => $rl['message']];
        }

        $forceDup = (int)($_POST['force_duplicate'] ?? ($_POST['dup_force'] ?? 0));
        $strictDuplicate = (int)($_POST['strict_duplicate'] ?? 0) === 1;
        $dupes = $this->findDuplicates($category, $title);

        if ($strictDuplicate && !$forceDup && count($dupes) > 0) {
            return [
                'error' => true,
                'code'  => 'POSSIBLE_DUPLICATE',
                'message' => 'Benzer raporlar bulundu. Aynı sorun olabilir.',
                'duplicates' => $dupes
            ];
        }

        try {
            $now = date('Y-m-d H:i:s');
            $reportId = DB::table('bug_reports')->insertGetId([
                'user_id'      => $userId,
                'category'     => $category,
                'title'        => $title,
                'description'  => $description,
                'image_url'    => $imageUrl,
                'error_code'   => $errorCode,
                'priority'     => $priority,
                'repro_rate'   => $reproRate,
                'status'       => 0,
                'votes_count'  => 0,
                'created_at'   => $now
            ]);

            DB::table('bug_events')->insert([
                'report_id'      => $reportId,
                'actor_user_id'  => $userId,
                'type'           => 'created',
                'meta'           => json_encode(['category' => $category, 'priority' => $priority], JSON_UNESCAPED_UNICODE),
                'created_at'     => $now
            ]);

            // Form açılır açılmaz takibe al
            DB::table('bug_subscriptions')->updateOrInsert(
                ['report_id' => $reportId, 'user_id' => $userId],
                ['created_at' => $now]
            );

            return [
                'error'     => false,
                'message'   => 'İstihbarat karargaha ulaştı. 🛰️',
                'report_id' => $reportId
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Sistem hatası: Rapor kaydedilemedi.'];
        }
    }

    /**
     * API: Oylama
     */
    public function toggleVote()
    {
        $session = App::session();
        $session->ensureLogged();
        $userId = (int) $session->getUid();

        if (!$this->validateCsrf($_POST['csrf_token'] ?? '')) {
            return ['error' => true, 'code' => 'CSRF', 'message' => 'Güvenlik doğrulaması başarısız.'];
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId <= 0) return ['error' => true, 'message' => 'Geçersiz rapor ID.'];

        $exists = DB::table('bug_reports')->where('id', $reportId)->exists();
        if (!$exists) return ['error' => true, 'message' => 'Rapor bulunamadı.'];

        $hasVoted = DB::table('bug_votes')
            ->where('report_id', $reportId)
            ->where('user_id', $userId)
            ->exists();

        if ($hasVoted) {
            DB::table('bug_votes')
                ->where('report_id', $reportId)
                ->where('user_id', $userId)
                ->delete();
            $isNowVoted = false;
        } else {
            DB::table('bug_votes')->insert([
                'report_id'  => $reportId,
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $isNowVoted = true;
        }

        $totalVotes = DB::table('bug_votes')->where('report_id', $reportId)->count();
        DB::table('bug_reports')->where('id', $reportId)->update(['votes_count' => $totalVotes]);

        return [
            'error'   => false,
            'message' => $hasVoted ? 'Oy kaldırıldı.' : 'Oy verildi.',
            'votes'   => (int)$totalVotes,
            'voted'   => $isNowVoted
        ];
    }

    /**
     * API: Takip Et
     */
    public function subscribe()
    {
        $session = App::session();
        $session->ensureLogged();
        $userId = (int) $session->getUid();

        if (!$this->validateCsrf($_POST['csrf_token'] ?? '')) {
            return ['error' => true, 'code' => 'CSRF', 'message' => 'Güvenlik doğrulaması başarısız.'];
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId <= 0) return ['error' => true, 'message' => 'Geçersiz rapor ID.'];

        DB::table('bug_subscriptions')->updateOrInsert(
            ['report_id' => $reportId, 'user_id' => $userId],
            ['created_at' => date('Y-m-d H:i:s')]
        );

        return [
            'error' => false,
            'message' => 'Takibe alındı.',
            'subscribed' => true
        ];
    }

    /**
     * API: Takibi Bırak
     */
    public function unsubscribe()
    {
        $session = App::session();
        $session->ensureLogged();
        $userId = (int) $session->getUid();

        if (!$this->validateCsrf($_POST['csrf_token'] ?? '')) {
            return ['error' => true, 'code' => 'CSRF', 'message' => 'Güvenlik doğrulaması başarısız.'];
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId <= 0) return ['error' => true, 'message' => 'Geçersiz rapor ID.'];

        DB::table('bug_subscriptions')
            ->where('report_id', $reportId)
            ->where('user_id', $userId)
            ->delete();

        return [
            'error' => false,
            'message' => 'Takip bırakıldı.',
            'subscribed' => false
        ];
    }

    /**
     * API: Admin Yanıtı ve Durum Güncelleme
     * POST /ajax/bugs/reply
     */
    public function adminReply()
    {
        $session = App::session();
        $session->ensureLogged();
        $adminId = (int) $session->getUid();

        // Kullanıcı admin mi kontrolü
        $isAdmin = DB::table('users')->where('id', $adminId)->value('is_admin') == 1;

        if (!$isAdmin) {
            return ['error' => true, 'message' => 'Yetkisiz erişim. Sadece adminler yanıtlayabilir.'];
        }

        if (!$this->validateCsrf($_POST['csrf_token'] ?? '')) {
            return ['error' => true, 'code' => 'CSRF', 'message' => 'Güvenlik doğrulaması başarısız.'];
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        $message  = trim($_POST['message'] ?? '');
        $status   = isset($_POST['status']) ? (int)$_POST['status'] : -1;

        if ($reportId <= 0 || $message === '' || $status < 0 || $status > 3) {
            return ['error' => true, 'message' => 'Eksik veya hatalı parametre.'];
        }

        $exists = DB::table('bug_reports')->where('id', $reportId)->exists();
        if (!$exists) {
            return ['error' => true, 'message' => 'Rapor bulunamadı.'];
        }

        $now = date('Y-m-d H:i:s');

        try {
            // Yanıtı Ekle
            DB::table('bug_replies')->insert([
                'report_id'  => $reportId,
                'admin_id'   => $adminId,
                'message'    => $message,
                'created_at' => $now
            ]);

            // Raporun durumunu güncelle
            DB::table('bug_reports')->where('id', $reportId)->update([
                'status' => $status
            ]);

            return [
                'error'   => false,
                'message' => 'Yanıt gönderildi ve durum güncellendi.'
            ];
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Veritabanı hatası: Yanıt eklenemedi.'];
        }
    }


    // ---------------------------
    // Helper Methods
    // ---------------------------

    private function jsonOut(array $payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function ensureCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_csrf_token'];
    }

    protected function validateCsrf($token)
    {
        $real = $this->ensureCsrfToken();
        return is_string($token) && hash_equals($real, $token);
    }

    private function parseClientTimestampToUnixSeconds($value): int
    {
        if ($value === null || $value === '') return 0;
        $v = is_numeric($value) ? (float)$value : 0;
        if ($v <= 0) return 0;
        if ($v > 10000000000) { $v = (int) floor($v / 1000); }
        return (int)$v;
    }

    private function looksMalicious(string $s): bool
    {
        $x = mb_strtolower($s, 'UTF-8');
        if (strpos($x, '<script') !== false) return true;
        if (strpos($x, '</script') !== false) return true;
        if (strpos($x, 'javascript:') !== false) return true;
        if (strpos($x, 'onerror=') !== false) return true;
        if (strpos($x, 'onload=') !== false) return true;
        return false;
    }

    private function isSafeHttpUrl(string $url): bool
    {
        $p = @parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return false;
        return in_array(strtolower($p['scheme']), ['http','https'], true);
    }

    private function checkRateLimit(int $userId): array
    {
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - (self::LIMIT_WINDOW_MIN * 60));
        $dayStart = date('Y-m-d 00:00:00', $now);

        $inWindow = DB::table('bug_reports')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $windowStart)
            ->count();

        if ($inWindow >= self::LIMIT_MAX_IN_WINDOW) {
            return ['blocked' => true, 'message' => 'Çok hızlı rapor gönderiyorsun. Lütfen birkaç dakika sonra tekrar dene.'];
        }

        $perDay = DB::table('bug_reports')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $dayStart)
            ->count();

        if ($perDay >= self::LIMIT_MAX_PER_DAY) {
            return ['blocked' => true, 'message' => 'Günlük rapor limitine ulaştın. Yarın tekrar deneyebilirsin.'];
        }

        return ['blocked' => false, 'message' => ''];
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private function similarityPct(string $a, string $b): float
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);
        if ($a === '' || $b === '') return 0.0;
        similar_text($a, $b, $pct);
        return (float)$pct;
    }

    private function findDuplicates(string $category, string $title): array
    {
        $tNorm = $this->normalize($title);
        $words = array_values(array_filter(explode(' ', $tNorm), fn($w) => mb_strlen($w, 'UTF-8') >= 4));
        $words = array_slice(array_unique($words), 0, 6);

        $since = date('Y-m-d H:i:s', time() - (30 * 86400));

        $q = DB::table('bug_reports')
            ->where('created_at', '>=', $since)
            ->where('category', $category);

        if (count($words) > 0) {
            $q->where(function ($qq) use ($words) {
                foreach ($words as $w) {
                    $qq->orWhere('title', 'like', '%' . $w . '%');
                }
            });
        }

        $candidates = $q->orderBy('created_at', 'desc')->limit(30)
            ->get(['id','title','status','votes_count','created_at']);

        $hits = [];
        foreach ($candidates as $c) {
            $pct = $this->similarityPct($title, $c->title);
            if ($pct >= 75.0) {
                $hits[] = [
                    'id' => (int)$c->id,
                    'title' => (string)$c->title,
                    'status' => (int)$c->status,
                    'votes' => (int)($c->votes_count ?? 0),
                    'created_at' => (string)$c->created_at,
                    'sim' => round($pct, 1)
                ];
            }
        }

        usort($hits, fn($a,$b) => $b['sim'] <=> $a['sim']);
        return array_slice($hits, 0, 5);
    }
}

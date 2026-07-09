<?php

namespace App\Controllers;

use App\Models\Shout as ShoutModel;
use App\Models\ShoutLike;
use App\Models\ShoutReport;
use App\System\ActionRateLimiter;
use App\System\Controller;
use App\System\Logger;
use App\System\Notify;
use Illuminate\Database\Capsule\Manager as DB;

class Shout extends Controller
{
    private static $schemaReadyCache = null;
    private static $featuresSchemaReadyCache = null;
    private static $restrictionsTableCache = null;
    private static $columnPresenceCache = [];

    const MIN_BODY_LENGTH = 3;
    const MAX_BODY_LENGTH = 240;
    const DEFAULT_PAGE_SIZE = 8;
    const REPORT_COOLDOWN_THRESHOLD = 4;
    const REPORT_COOLDOWN_HOURS = 12;
    const REPORT_TARGET_LIMIT = 3;
    const REPORT_TARGET_WINDOW_HOURS = 12;
    const REPORT_TOTAL_LIMIT = 10;
    const REPORT_TOTAL_COOLDOWN_HOURS = 6;
    const AUTO_HIDE_REPORT_THRESHOLD = 3;
    const LIKE_TARGET_LIMIT = 6;
    const LIKE_TARGET_WINDOW_HOURS = 12;
    const EDIT_WINDOW_MINUTES = 15;
    const AUTO_REPORT_RESTRICTION_THRESHOLD = 8;
    const AUTO_DELETE_RESTRICTION_THRESHOLD = 3;
    const POST_MIN_INTERVAL_SECONDS = 20;
    const POST_MINUTE_LIMIT = 3;
    const POST_BURST_LIMIT = 6;
    const POST_DAILY_LIMIT = 10;
    const POLL_MIN_OPTIONS = 2;
    const POLL_MAX_OPTIONS = 3;
    const POLL_OPTION_MAX_LENGTH = 80;
    const POLL_DURATION_COSTS = [
        1 => 1,
        6 => 3,
        24 => 8,
    ];
    const TIP_ALLOWED_AMOUNTS = [1, 5];
    const DECREE_DURATION_HOURS = 24;
    const DECREE_COST_CURRENCY = 'CC';
    const DECREE_COST_AMOUNT = 250.00;
    const MEDIA_BLACKOUT_DURATION_HOURS = 1;
    const MEDIA_BLACKOUT_COST_CURRENCY = 'Gold';
    const MEDIA_BLACKOUT_COST_AMOUNT = 100.00;
    const MEDIA_REPUTATION_LIKE = 2;
    const MEDIA_REPUTATION_TIP_MULTIPLIER = 3;
    const MEDIA_REPUTATION_REPORT = -1;
    const MEDIA_REPUTATION_ADMIN_DELETE = -8;
    const MEDIA_REPUTATION_VERIFIED = 25;
    const MEDIA_REPUTATION_LEGENDARY = 70;
    const MEDIA_REPUTATION_BLUR = -15;
    const REPLY_PREVIEW_LIMIT = 3;
    const MENTION_REGEX = '/(?<![A-Za-z0-9_])@([A-Za-z0-9_]{3,20})/u';
    const TREND_NOTIFICATION_SCORE = 8.0;
    const COUNTRY_CRITICAL_SCORE = 12.0;
    const COUNTRY_CRITICAL_REPORTS = 3;

    private function currentUserId()
    {
        return (int) $this->container->get('session')->getUid();
    }

    private function getUserLocale($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return 'tr';
        }

        try {
            $locale = (string) DB::table('users')->where('id', $uid)->value('language');
            $locale = strtolower(trim($locale));

            if ($locale === 'en') {
                return 'en';
            }
        } catch (\Exception $e) {
        }

        return 'tr';
    }

    private function translateForUser($uid, $key, array $replace = [])
    {
        return $this->container
            ->get('i18n')
            ->getTranslator()
            ->translate($key, $replace, $this->getUserLocale($uid));
    }

    private function normalizeBody($body)
    {
        $body = $this->sanitizeForDatabaseText($body);
        $body = trim((string) $body);
        $body = preg_replace('/\s+/u', ' ', $body);

        return trim((string) $body);
    }

    private function sanitizeForDatabaseText($value)
    {
        $value = (string) $value;

        // Legacy utf8 columns reject 4-byte characters like many emoji.
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value);

        return (string) $value;
    }

    private function currentUserIsAdmin()
    {
        try {
            return (int) DB::table('users')
                ->where('id', $this->currentUserId())
                ->value('is_admin') === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function currentUserIsAdminStatic($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return false;
        }

        try {
            return (int) DB::table('users')
                ->where('id', $uid)
                ->value('is_admin') === 1;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isSchemaReady()
    {
        if (self::$schemaReadyCache !== null) {
            return self::$schemaReadyCache;
        }

        try {
            $schema = DB::getSchemaBuilder();

            self::$schemaReadyCache = $schema->hasTable('shouts')
                && $schema->hasTable('shout_likes')
                && $schema->hasTable('shout_reports');
        } catch (\Exception $e) {
            self::$schemaReadyCache = false;
        }

        return self::$schemaReadyCache;
    }

    private function isFeatureSchemaReady()
    {
        if (self::$featuresSchemaReadyCache !== null) {
            return self::$featuresSchemaReadyCache;
        }

        try {
            $schema = DB::getSchemaBuilder();

            self::$featuresSchemaReadyCache = $this->isSchemaReady()
                && $schema->hasTable('shout_poll_votes')
                && $schema->hasTable('shout_tips')
                && $schema->hasTable('user_shout_limits')
                && $schema->hasTable('country_media_blackouts')
                && $schema->hasColumn('users', 'media_reputation')
                && $schema->hasColumn('shouts', 'parent_id')
                && $schema->hasColumn('shouts', 'has_poll')
                && $schema->hasColumn('shouts', 'poll_question')
                && $schema->hasColumn('shouts', 'poll_data')
                && $schema->hasColumn('shouts', 'poll_total_votes')
                && $schema->hasColumn('shouts', 'poll_duration_hours')
                && $schema->hasColumn('shouts', 'poll_expires_at')
                && $schema->hasColumn('shouts', 'poll_cost_gold')
                && $schema->hasColumn('shouts', 'tips_gold_total')
                && $schema->hasColumn('shouts', 'is_state_decree')
                && $schema->hasColumn('shouts', 'decree_country_id')
                && $schema->hasColumn('shouts', 'decree_expires_at')
                && $schema->hasColumn('shouts', 'decree_cost_currency')
                && $schema->hasColumn('shouts', 'decree_cost_amount')
                && $schema->hasColumn('shouts', 'article_card_article_id');
        } catch (\Exception $e) {
            self::$featuresSchemaReadyCache = false;
        }

        return self::$featuresSchemaReadyCache;
    }

    public static function featuresSchemaReady()
    {
        if (self::$featuresSchemaReadyCache !== null) {
            return self::$featuresSchemaReadyCache;
        }

        try {
            $schema = DB::getSchemaBuilder();

            self::$featuresSchemaReadyCache = $schema->hasTable('shouts')
                && $schema->hasTable('shout_likes')
                && $schema->hasTable('shout_reports')
                && $schema->hasTable('shout_poll_votes')
                && $schema->hasTable('shout_tips')
                && $schema->hasTable('user_shout_limits')
                && $schema->hasTable('country_media_blackouts')
                && $schema->hasColumn('users', 'media_reputation')
                && $schema->hasColumn('shouts', 'parent_id')
                && $schema->hasColumn('shouts', 'has_poll')
                && $schema->hasColumn('shouts', 'poll_question')
                && $schema->hasColumn('shouts', 'poll_data')
                && $schema->hasColumn('shouts', 'poll_total_votes')
                && $schema->hasColumn('shouts', 'poll_duration_hours')
                && $schema->hasColumn('shouts', 'poll_expires_at')
                && $schema->hasColumn('shouts', 'poll_cost_gold')
                && $schema->hasColumn('shouts', 'tips_gold_total')
                && $schema->hasColumn('shouts', 'is_state_decree')
                && $schema->hasColumn('shouts', 'decree_country_id')
                && $schema->hasColumn('shouts', 'decree_expires_at')
                && $schema->hasColumn('shouts', 'decree_cost_currency')
                && $schema->hasColumn('shouts', 'decree_cost_amount')
                && $schema->hasColumn('shouts', 'article_card_article_id');
        } catch (\Exception $e) {
            self::$featuresSchemaReadyCache = false;
        }

        return self::$featuresSchemaReadyCache;
    }

    private static function hasRestrictionsTable()
    {
        if (self::$restrictionsTableCache !== null) {
            return self::$restrictionsTableCache;
        }

        try {
            self::$restrictionsTableCache = DB::getSchemaBuilder()->hasTable('shout_user_restrictions');
        } catch (\Exception $e) {
            self::$restrictionsTableCache = false;
        }

        return self::$restrictionsTableCache;
    }

    private function getPresidentCountry($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return null;
        }

        try {
            return DB::table('countries')
                ->where('president', $uid)
                ->first(['id', 'name', 'currency', 'president']);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function resolveUserCountryId($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return 0;
        }

        try {
            $row = DB::table('users as u')
                ->leftJoin('regions as r', 'r.id', '=', 'u.region')
                ->where('u.id', $uid)
                ->first([
                    'u.country_id',
                    'u.region',
                    DB::raw('COALESCE(u.country_id, r.country, 0) as effective_country_id'),
                ]);

            return (int) ($row->effective_country_id ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    private static function getActiveBlackout($countryId)
    {
        $countryId = (int) $countryId;
        if ($countryId < 1) {
            return null;
        }

        try {
            return DB::table('country_media_blackouts')
                ->where('country_id', $countryId)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->orderBy('expires_at', 'desc')
                ->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function isCongressMemberOfCountry($uid, $countryId)
    {
        $uid = (int) $uid;
        $countryId = (int) $countryId;
        if ($uid < 1 || $countryId < 1) {
            return false;
        }

        try {
            return DB::table('congress_members')
                ->where('uid', $uid)
                ->where('country', $countryId)
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function canPostDuringBlackout($uid, $countryId)
    {
        $uid = (int) $uid;
        $countryId = (int) $countryId;
        if ($uid < 1 || $countryId < 1) {
            return false;
        }

        try {
            $isPresident = (int) DB::table('countries')
                ->where('id', $countryId)
                ->value('president') === $uid;

            if ($isPresident) {
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }

        return self::isCongressMemberOfCountry($uid, $countryId);
    }

    private static function buildBlackoutPayload($row)
    {
        if (!$row) {
            return [
                'active' => false,
                'country_id' => 0,
                'expires_at' => null,
                'remaining_seconds' => 0,
                'remaining_label' => '',
                'cost_currency' => self::MEDIA_BLACKOUT_COST_CURRENCY,
                'cost_amount' => self::MEDIA_BLACKOUT_COST_AMOUNT,
            ];
        }

        $expiresAt = !empty($row->expires_at) ? strtotime((string) $row->expires_at) : 0;
        $remainingSeconds = $expiresAt > time() ? max(1, $expiresAt - time()) : 0;

        return [
            'active' => $remainingSeconds > 0,
            'country_id' => (int) ($row->country_id ?? 0),
            'expires_at' => (string) ($row->expires_at ?? ''),
            'remaining_seconds' => $remainingSeconds,
            'remaining_label' => self::humanRemaining($remainingSeconds),
            'cost_currency' => (string) ($row->cost_currency ?? self::MEDIA_BLACKOUT_COST_CURRENCY),
            'cost_amount' => round((float) ($row->cost_amount ?? self::MEDIA_BLACKOUT_COST_AMOUNT), 2),
        ];
    }

    private static function hasColumnCached($table, $column)
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnPresenceCache)) {
            return self::$columnPresenceCache[$key];
        }

        try {
            self::$columnPresenceCache[$key] = DB::getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Exception $e) {
            self::$columnPresenceCache[$key] = false;
        }

        return self::$columnPresenceCache[$key];
    }

    private function getUserBlackoutPayload($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->isFeatureSchemaReady()) {
            return self::buildBlackoutPayload(null);
        }

        $countryId = self::resolveUserCountryId($uid);
        if ($countryId < 1) {
            return self::buildBlackoutPayload(null);
        }

        return self::buildBlackoutPayload(self::getActiveBlackout($countryId));
    }

    private function detectArticleCardArticleId($body)
    {
        $body = (string) $body;
        $patterns = [
            '~(?:https?://[^\s]+)?/news/article/(\d+)~i',
            '~(?:https?://[^\s]+)?/article/(\d+)~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $articleId = (int) ($matches[1] ?? 0);
                if ($articleId > 0) {
                    $exists = (bool) DB::table('newspaper_articles')->where('id', $articleId)->exists();
                    if ($exists) {
                        return $articleId;
                    }
                }
            }
        }

        return 0;
    }

    private static function stripArticleLinksFromBody($body)
    {
        $body = preg_replace('~(?:https?://[^\s]+)?/news/article/\d+~i', ' ', (string) $body);
        $body = preg_replace('~(?:https?://[^\s]+)?/article/\d+~i', ' ', (string) $body);
        $body = preg_replace('/\s+/u', ' ', (string) $body);
        return trim((string) $body);
    }

    private static function escapeBodyHtml($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function normalizeMentionNick($nick)
    {
        $nick = trim((string) $nick);
        return function_exists('mb_strtolower') ? mb_strtolower($nick, 'UTF-8') : strtolower($nick);
    }

    private static function extractMentionNicknames($body)
    {
        preg_match_all(self::MENTION_REGEX, (string) $body, $matches);
        $mentions = [];

        foreach (($matches[1] ?? []) as $nick) {
            $normalized = self::normalizeMentionNick($nick);
            if ($normalized !== '') {
                $mentions[] = $normalized;
            }
        }

        return array_values(array_unique($mentions));
    }

    private static function resolveMentionUsers(array $nicknames)
    {
        $nicknames = array_values(array_unique(array_filter(array_map(function ($nick) {
            return self::normalizeMentionNick($nick);
        }, $nicknames))));

        if (empty($nicknames)) {
            return [];
        }

        $query = DB::table('users')->select(['id', 'nick']);
        $query->where(function ($innerQuery) use ($nicknames) {
            foreach ($nicknames as $index => $nickname) {
                if ($index === 0) {
                    $innerQuery->whereRaw('LOWER(nick) = ?', [$nickname]);
                } else {
                    $innerQuery->orWhereRaw('LOWER(nick) = ?', [$nickname]);
                }
            }
        });

        $rows = $query->get();
        $users = [];

        foreach ($rows as $row) {
            $normalized = self::normalizeMentionNick($row->nick ?? '');
            if ($normalized === '') {
                continue;
            }

            $users[$normalized] = [
                'id' => (int) ($row->id ?? 0),
                'nick' => (string) ($row->nick ?? ''),
            ];
        }

        return $users;
    }

    private static function renderBodyHtml($body, array $mentionUsers = [])
    {
        $body = (string) $body;
        if ($body === '') {
            return '';
        }

        $matched = preg_match_all(self::MENTION_REGEX, $body, $fullMatches, PREG_OFFSET_CAPTURE);
        if (!$matched || empty($fullMatches[0])) {
            return self::escapeBodyHtml($body);
        }

        preg_match_all(self::MENTION_REGEX, $body, $nickMatches, PREG_OFFSET_CAPTURE);

        $html = '';
        $lastOffset = 0;

        foreach ($fullMatches[0] as $index => $matchData) {
            $fullMatch = (string) ($matchData[0] ?? '');
            $offset = (int) ($matchData[1] ?? 0);
            $length = strlen($fullMatch);

            if ($offset > $lastOffset) {
                $html .= self::escapeBodyHtml(substr($body, $lastOffset, $offset - $lastOffset));
            }

            $mentionNick = (string) ($nickMatches[1][$index][0] ?? '');
            $mentionUser = $mentionUsers[self::normalizeMentionNick($mentionNick)] ?? null;

            if (!empty($mentionUser['id'])) {
                $html .= '<a href="/citizen/' . (int) $mentionUser['id'] . '" class="shout-mention-link" data-mention-uid="' . (int) $mentionUser['id'] . '">' . self::escapeBodyHtml($fullMatch) . '</a>';
            } else {
                $html .= self::escapeBodyHtml($fullMatch);
            }

            $lastOffset = $offset + $length;
        }

        if ($lastOffset < strlen($body)) {
            $html .= self::escapeBodyHtml(substr($body, $lastOffset));
        }

        return $html;
    }

    public static function buildDisplayBodyHtml($body, array $mentionUsers = null)
    {
        $displayBody = self::stripArticleLinksFromBody($body);
        if ($mentionUsers === null) {
            $mentionUsers = self::resolveMentionUsers(self::extractMentionNicknames($displayBody));
        }

        return self::renderBodyHtml($displayBody, $mentionUsers);
    }

    private static function reputationProfile($score)
    {
        $score = (int) $score;
        return [
            'score' => $score,
            'is_verified' => $score >= self::MEDIA_REPUTATION_VERIFIED,
            'is_legendary' => $score >= self::MEDIA_REPUTATION_LEGENDARY,
            'is_blurred' => $score <= self::MEDIA_REPUTATION_BLUR,
        ];
    }

    private static function adjustMediaReputation($uid, $delta)
    {
        $uid = (int) $uid;
        $delta = (int) $delta;
        if ($uid < 1 || $delta === 0) {
            return;
        }

        try {
            DB::table('users')
                ->where('id', $uid)
                ->update([
                    'media_reputation' => DB::raw('COALESCE(media_reputation, 0) + (' . $delta . ')'),
                ]);
        } catch (\Exception $e) {
            Logger::warning('Media reputation update failed.', [
                'uid' => $uid,
                'delta' => $delta,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function parsePollOptions()
    {
        $rawOptions = [
            $_POST['poll_option_1'] ?? '',
            $_POST['poll_option_2'] ?? '',
            $_POST['poll_option_3'] ?? '',
        ];

        $options = [];
        foreach ($rawOptions as $index => $rawOption) {
            $option = $this->normalizeBody($rawOption);
            if ($option === '') {
                continue;
            }

            $length = function_exists('mb_strlen') ? mb_strlen($option, 'UTF-8') : strlen($option);
            if ($length < 1 || $length > self::POLL_OPTION_MAX_LENGTH) {
                throw new \Exception('Anket secenekleri 1 ile ' . self::POLL_OPTION_MAX_LENGTH . ' karakter arasinda olmali.');
            }

            $normalized = function_exists('mb_strtolower') ? mb_strtolower($option, 'UTF-8') : strtolower($option);
            if (isset($options[$normalized])) {
                throw new \Exception('Anket secenekleri birbiriyle ayni olamaz.');
            }

            $options[$normalized] = [
                'option_key' => $index + 1,
                'option_text' => $option,
            ];
        }

        $count = count($options);
        if ($count > 0 && ($count < self::POLL_MIN_OPTIONS || $count > self::POLL_MAX_OPTIONS)) {
            throw new \Exception('Anket icin 2 veya 3 secenek girmelisin.');
        }

        return array_values($options);
    }

    private function parsePollDurationHours()
    {
        $hours = (int) ($_POST['poll_duration_hours'] ?? 0);
        if (!array_key_exists($hours, self::POLL_DURATION_COSTS)) {
            throw new \Exception('Anket suresi 1, 6 veya 24 saat olmalidir.');
        }

        return $hours;
    }

    private static function attachFeedExtras(array $items, $viewerUid = 0, $isAdmin = false)
    {
        if (empty($items)) {
            return $items;
        }

        $viewerUid = (int) $viewerUid;
        $shoutIds = array_values(array_unique(array_map(function ($item) {
            return (int) ($item['id'] ?? 0);
        }, $items)));

        if (empty($shoutIds)) {
            return $items;
        }

        $displayBodyMap = [];
        $mentionNicknames = [];

        foreach ($items as $item) {
            $shoutId = (int) ($item['id'] ?? 0);
            $displayBodyMap[$shoutId] = self::stripArticleLinksFromBody($item['body'] ?? '');
            $mentionNicknames = array_merge($mentionNicknames, self::extractMentionNicknames($displayBodyMap[$shoutId]));
        }

        $mentionUserMap = self::resolveMentionUsers($mentionNicknames);

        $pollMap = [];
        foreach ($items as $item) {
            $shoutId = (int) ($item['id'] ?? 0);
            $options = ShoutModel::decodePollData($item['poll_data'] ?? null);
            $pollMap[$shoutId] = [
                'options' => $options,
                'total_votes' => (int) ($item['poll_total_votes'] ?? 0),
                'my_option_id' => 0,
            ];
        }

        if ($viewerUid > 0 && !empty($pollMap)) {
            $voteRows = DB::table('shout_poll_votes')
                ->where('uid', $viewerUid)
                ->whereIn('shout_id', array_keys($pollMap))
                ->get(['shout_id', 'poll_option_id']);

            foreach ($voteRows as $voteRow) {
                $shoutId = (int) $voteRow->shout_id;
                if (isset($pollMap[$shoutId])) {
                    $pollMap[$shoutId]['my_option_id'] = (int) $voteRow->poll_option_id;
                }
            }
        }

        $tipRows = DB::table('shout_tips')
            ->whereIn('shout_id', $shoutIds)
            ->selectRaw('shout_id, COUNT(*) as tips_count, COALESCE(SUM(gold_amount), 0) as total_gold')
            ->groupBy('shout_id')
            ->get();

        $tipMap = [];
        foreach ($tipRows as $tipRow) {
            $tipMap[(int) $tipRow->shout_id] = [
                'tips_count' => (int) ($tipRow->tips_count ?? 0),
                'total_gold' => (float) ($tipRow->total_gold ?? 0),
            ];
        }

        $reportSummaryMap = [];
        if ($isAdmin) {
            $reportRows = DB::table('shout_reports')
                ->leftJoin('users as ru', 'ru.id', '=', 'shout_reports.uid')
                ->leftJoin('regions as rr', 'rr.id', '=', 'ru.region')
                ->whereIn('shout_id', $shoutIds)
                ->orderBy('shout_reports.created_at', 'desc')
                ->orderBy('shout_reports.id', 'desc')
                ->get([
                    'shout_id',
                    'reason',
                    'shout_reports.created_at as created_at',
                    'shout_reports.uid as reporter_uid',
                    'ru.nick as reporter_nick',
                    DB::raw('COALESCE(ru.country_id, rr.country, 0) as reporter_country_id'),
                ]);

            foreach ($reportRows as $reportRow) {
                $reportShoutId = (int) ($reportRow->shout_id ?? 0);
                if ($reportShoutId < 1 || isset($reportSummaryMap[$reportShoutId])) {
                    continue;
                }

                $reportSummaryMap[$reportShoutId] = [
                    'latest_reason' => trim((string) ($reportRow->reason ?? '')),
                    'created_at' => (string) ($reportRow->created_at ?? ''),
                    'latest_reporter_uid' => (int) ($reportRow->reporter_uid ?? 0),
                    'latest_reporter_nick' => (string) ($reportRow->reporter_nick ?? ''),
                    'latest_reporter_country_id' => (int) ($reportRow->reporter_country_id ?? 0),
                ];
            }
        }

        $articleIds = array_values(array_unique(array_filter(array_map(function ($item) {
            return (int) ($item['article_card_article_id'] ?? 0);
        }, $items))));
        $articleMap = [];
        if (!empty($articleIds)) {
            $articleRows = DB::table('newspaper_articles as a')
                ->leftJoin('users as au', 'au.id', '=', 'a.uid')
                ->leftJoin('newspapers as n', 'n.uid', '=', 'a.uid')
                ->whereIn('a.id', $articleIds)
                ->get([
                    'a.id',
                    'a.uid',
                    'a.title',
                    'a.votes',
                    'a.category',
                    'a.country',
                    'au.nick as author_nick',
                    'n.id as newspaper_id',
                    'n.name as newspaper_name',
                    'n.subscribers as newspaper_subscribers',
                ]);

            $votedArticleIds = [];
            $subscribedNewspaperIds = [];
            if ($viewerUid > 0) {
                $votedArticleIds = DB::table('article_votes')
                    ->where('uid', $viewerUid)
                    ->whereIn('article', $articleIds)
                    ->pluck('article')
                    ->map(function ($id) {
                        return (int) $id;
                    })
                    ->toArray();

                $newspaperIds = [];
                foreach ($articleRows as $articleRow) {
                    $newspaperId = (int) ($articleRow->newspaper_id ?? 0);
                    if ($newspaperId > 0) {
                        $newspaperIds[] = $newspaperId;
                    }
                }
                $newspaperIds = array_values(array_unique($newspaperIds));

                if (!empty($newspaperIds)) {
                    $subscribedNewspaperIds = DB::table('newspaper_subscribers')
                        ->where('uid', $viewerUid)
                        ->whereIn('newspaper_id', $newspaperIds)
                        ->pluck('newspaper_id')
                        ->map(function ($id) {
                            return (int) $id;
                        })
                        ->toArray();
                }
            }

            $votedLookup = array_fill_keys($votedArticleIds, true);
            $subscribedLookup = array_fill_keys($subscribedNewspaperIds, true);

            foreach ($articleRows as $articleRow) {
                $articleId = (int) ($articleRow->id ?? 0);
                $newspaperId = (int) ($articleRow->newspaper_id ?? 0);
                $articleMap[(int) $articleRow->id] = [
                    'id' => $articleId,
                    'uid' => (int) ($articleRow->uid ?? 0),
                    'title' => (string) ($articleRow->title ?? 'Makale'),
                    'votes' => (int) ($articleRow->votes ?? 0),
                    'category' => (int) ($articleRow->category ?? 0),
                    'country_id' => (int) ($articleRow->country ?? 0),
                    'author_nick' => (string) ($articleRow->author_nick ?? 'Basin Yazari'),
                    'newspaper_id' => $newspaperId,
                    'newspaper_name' => (string) ($articleRow->newspaper_name ?? 'Bagimsiz Gazete'),
                    'newspaper_subscribers' => (int) ($articleRow->newspaper_subscribers ?? 0),
                    'voted_by_me' => isset($votedLookup[$articleId]),
                    'subscribed_by_me' => $newspaperId > 0 && isset($subscribedLookup[$newspaperId]),
                ];
            }
        }

        $now = time();
        foreach ($items as &$item) {
            $shoutId = (int) ($item['id'] ?? 0);
            $pollData = $pollMap[$shoutId] ?? ['options' => [], 'total_votes' => 0, 'my_option_id' => 0];
            $totalVotes = max(0, (int) ($pollData['total_votes'] ?? 0));
            $myOptionId = (int) ($pollData['my_option_id'] ?? 0);

            foreach ($pollData['options'] as &$option) {
                $votesCount = (int) ($option['votes_count'] ?? 0);
                $option['percent'] = $totalVotes > 0 ? round(($votesCount / $totalVotes) * 100, 1) : 0;
                $option['voted_by_me'] = (int) $option['id'] === $myOptionId;
            }
            unset($option);

            $item['poll'] = [
                'has_poll' => !empty($item['has_poll']),
                'question' => (string) ($item['poll_question'] ?? ''),
                'options' => $pollData['options'],
                'total_votes' => $totalVotes,
                'has_voted' => $myOptionId > 0,
                'my_option_id' => $myOptionId,
                'duration_hours' => (int) ($item['poll_duration_hours'] ?? 0),
                'cost_gold' => round((float) ($item['poll_cost_gold'] ?? 0), 2),
                'expires_at' => (string) ($item['poll_expires_at'] ?? ''),
                'is_closed' => !empty($item['poll_expires_at']) && strtotime((string) $item['poll_expires_at']) <= $now,
                'remaining_label' => !empty($item['poll_expires_at']) && strtotime((string) $item['poll_expires_at']) > $now
                    ? self::humanRemaining(strtotime((string) $item['poll_expires_at']) - $now)
                    : 'Kapandi',
            ];

            $tipData = $tipMap[$shoutId] ?? ['tips_count' => 0, 'total_gold' => (float) ($item['tips_gold_total'] ?? 0)];
            $item['tips'] = [
                'total_gold' => round((float) ($tipData['total_gold'] ?? 0), 2),
                'tips_count' => (int) ($tipData['tips_count'] ?? 0),
            ];

            $decreeExpiresAt = !empty($item['decree_expires_at']) ? strtotime((string) $item['decree_expires_at']) : 0;
            $item['is_active_decree'] = !empty($item['is_state_decree']) && $decreeExpiresAt > $now;
            $item['can_tip'] = $viewerUid > 0 && (int) ($item['uid'] ?? 0) !== $viewerUid;
            $item['show_report_badge'] = $isAdmin && (int) ($item['reports_count'] ?? 0) > 0;
            $item['display_body'] = $displayBodyMap[$shoutId] ?? self::stripArticleLinksFromBody($item['body'] ?? '');
            $item['display_body_html'] = self::renderBodyHtml($item['display_body'], $mentionUserMap);
            $item['reputation'] = self::reputationProfile((int) ($item['media_reputation'] ?? 0));
            $item['report_summary'] = null;
            if ($isAdmin && (int) ($item['reports_count'] ?? 0) > 0) {
                $reportSummary = $reportSummaryMap[$shoutId] ?? ['latest_reason' => '', 'created_at' => ''];
                $item['report_summary'] = [
                    'count' => (int) ($item['reports_count'] ?? 0),
                    'latest_reason' => (string) ($reportSummary['latest_reason'] ?? ''),
                    'created_at' => (string) ($reportSummary['created_at'] ?? ''),
                    'created_at_label' => !empty($reportSummary['created_at']) ? self::relativeTimeLabel($reportSummary['created_at']) : '',
                    'latest_reporter_uid' => (int) ($reportSummary['latest_reporter_uid'] ?? 0),
                    'latest_reporter_nick' => (string) ($reportSummary['latest_reporter_nick'] ?? ''),
                    'latest_reporter_country_id' => (int) ($reportSummary['latest_reporter_country_id'] ?? 0),
                ];
            }
            $item['article_card'] = null;

            $articleCardId = (int) ($item['article_card_article_id'] ?? 0);
            if ($articleCardId > 0 && isset($articleMap[$articleCardId])) {
                $articleCard = $articleMap[$articleCardId];
                $articleCard['url'] = '/news/article/' . $articleCard['id'];
                $item['article_card'] = $articleCard;
            }
        }
        unset($item);

        return $items;
    }

    private function containsBlockedLink($body)
    {
        return preg_match('/(https?:\/\/|www\.|discord\.gg\/|discord\.com\/invite|t\.me\/|bit\.ly\/|tinyurl\.com\/|goo\.gl\/)/iu', (string) $body) === 1;
    }

    private function containsBlockedTerm($body)
    {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower((string) $body, 'UTF-8') : strtolower((string) $body);
        $terms = [
            'amk',
            'aq',
            'orospu',
            'pic',
            'piç',
            'sik',
            'yarak',
            'casino',
            'bahis',
            'kumar',
            'porn',
            'porno',
            'onlyfans',
        ];

        foreach ($terms as $term) {
            $hasMatch = function_exists('mb_strpos')
                ? mb_strpos($normalized, $term) !== false
                : strpos($normalized, $term) !== false;
            if ($term !== '' && $hasMatch) {
                return true;
            }
        }

        return false;
    }

    private function normalizedComparableBody($body)
    {
        $body = function_exists('mb_strtolower') ? mb_strtolower((string) $body, 'UTF-8') : strtolower((string) $body);
        $body = preg_replace('/[^a-z0-9]+/i', '', $body);
        return trim((string) $body);
    }

    private function isLowQualityBody($body)
    {
        $body = trim((string) $body);
        $plain = $this->normalizedComparableBody($body);

        if ($plain === '' || strlen($plain) < 3) {
            return true;
        }

        if (preg_match('/^(.)\1+$/u', $plain)) {
            return true;
        }

        $bannedShort = ['sa', 'as', 'ok', 'gg', 'up', 'hey', 'selam', 'merhaba', 'test'];
        if (in_array($plain, $bannedShort, true)) {
            return true;
        }

        return false;
    }

    private function looksLikeCopyPasteSpam($uid, $body, $excludeShoutId = 0)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->isSchemaReady()) {
            return false;
        }

        $candidate = $this->normalizedComparableBody($body);
        if ($candidate === '') {
            return false;
        }

        $query = DB::table('shouts')
            ->where('uid', $uid)
            ->where('is_deleted', 0)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
            ->orderBy('id', 'desc')
            ->limit(5);

        if ((int) $excludeShoutId > 0) {
            $query->where('id', '!=', (int) $excludeShoutId);
        }

        $recentBodies = $query->pluck('body')->toArray();

        foreach ($recentBodies as $recentBody) {
            $recentComparable = $this->normalizedComparableBody($recentBody);
            if ($recentComparable === '') {
                continue;
            }

            similar_text($candidate, $recentComparable, $percent);
            if ($percent >= 88 || $candidate === $recentComparable) {
                return true;
            }
        }

        return false;
    }

    private function getManualMuteUntil($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !self::hasRestrictionsTable()) {
            return null;
        }

        try {
            $mutedUntil = DB::table('shout_user_restrictions')
                ->where('uid', $uid)
                ->value('muted_until');

            if (empty($mutedUntil)) {
                return null;
            }

            $timestamp = strtotime((string) $mutedUntil);
            if (!$timestamp || $timestamp <= time()) {
                return null;
            }

            return $timestamp;
        } catch (\Exception $e) {
            Logger::warning('Manual shout mute check failed.', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getPostingCooldownUntil($uid)
    {
        $restriction = self::getRestrictionState($uid);
        if (!empty($restriction['is_muted']) && !empty($restriction['remaining_seconds'])) {
            return time() + (int) $restriction['remaining_seconds'];
        }

        return null;
    }

    private function getPostingLimitViolation($uid, $now = null)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->isFeatureSchemaReady()) {
            return null;
        }

        $now = $now ?: date('Y-m-d H:i:s');
        $timestamp = strtotime($now);
        $state = ShoutModel::syncPostingCounter($uid, $now);
        $lastShoutAt = !empty($state['last_shout_at']) ? strtotime((string) $state['last_shout_at']) : 0;

        if ($lastShoutAt && ($timestamp - $lastShoutAt) < self::POST_MIN_INTERVAL_SECONDS) {
            $remaining = self::POST_MIN_INTERVAL_SECONDS - ($timestamp - $lastShoutAt);
            return [
                'message' => 'Cok hizli shout paylasiyorsun. Lutfen biraz bekle.',
                'retry_after' => max(1, $remaining),
                'state' => $state,
            ];
        }

        if ((int) ($state['minute_count'] ?? 0) >= self::POST_MINUTE_LIMIT) {
            $windowStart = strtotime((string) $state['minute_window_started_at']);
            $remaining = max(1, 60 - ($timestamp - $windowStart));
            return [
                'message' => 'Son 1 dakikada fazla shout paylastin. Lutfen biraz bekle.',
                'retry_after' => $remaining,
                'state' => $state,
            ];
        }

        if ((int) ($state['burst_count'] ?? 0) >= self::POST_BURST_LIMIT) {
            $windowStart = strtotime((string) $state['burst_window_started_at']);
            $remaining = max(1, 300 - ($timestamp - $windowStart));
            return [
                'message' => 'Kisa surede cok fazla shout paylastin. Daha sonra tekrar dene.',
                'retry_after' => $remaining,
                'state' => $state,
            ];
        }

        if ((int) ($state['daily_count'] ?? 0) >= self::POST_DAILY_LIMIT) {
            $dayStart = strtotime((string) $state['day_window_started_at']);
            $remaining = max(1, 86400 - ($timestamp - $dayStart));
            return [
                'message' => 'Gunluk shout sinirina ulastin. Daha sonra tekrar dene.',
                'retry_after' => $remaining,
                'state' => $state,
            ];
        }

        return null;
    }

    private function registerSuccessfulPost($uid, $now = null)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->isFeatureSchemaReady()) {
            return;
        }

        $now = $now ?: date('Y-m-d H:i:s');
        $state = ShoutModel::syncPostingCounter($uid, $now);
        $state['minute_count'] = (int) ($state['minute_count'] ?? 0) + 1;
        $state['burst_count'] = (int) ($state['burst_count'] ?? 0) + 1;
        $state['daily_count'] = (int) ($state['daily_count'] ?? 0) + 1;
        $state['last_shout_at'] = $now;
        ShoutModel::updatePostingCounter($uid, $state, $now);
    }

    public static function getRestrictionState($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            $baseState = [
                'is_muted' => false,
                'muted_until' => null,
                'remaining_seconds' => 0,
                'reason' => '',
                'reason_key' => '',
                'blackout' => self::buildBlackoutPayload(null),
            ];
            return $baseState;
        }

        if (!self::hasRestrictionsTable()) {
            $state = [
                'is_muted' => false,
                'muted_until' => null,
                'remaining_seconds' => 0,
                'reason' => '',
                'reason_key' => '',
                'blackout' => self::buildBlackoutPayload(null),
            ];
            return self::appendBlackoutRestriction($uid, $state);
        }

        try {
            $row = DB::table('shout_user_restrictions')
                ->where('uid', $uid)
                ->first(['muted_until', 'reason']);

            if (!$row || empty($row->muted_until)) {
                $state = [
                    'is_muted' => false,
                    'muted_until' => null,
                    'remaining_seconds' => 0,
                    'reason' => '',
                    'reason_key' => '',
                    'blackout' => self::buildBlackoutPayload(null),
                ];
                return self::appendBlackoutRestriction($uid, $state);
            }

            $timestamp = strtotime((string) $row->muted_until);
            if (!$timestamp || $timestamp <= time()) {
                $state = [
                    'is_muted' => false,
                    'muted_until' => null,
                    'remaining_seconds' => 0,
                    'reason' => '',
                    'reason_key' => '',
                    'blackout' => self::buildBlackoutPayload(null),
                ];
                return self::appendBlackoutRestriction($uid, $state);
            }

            return [
                'is_muted' => true,
                'muted_until' => (string) $row->muted_until,
                'remaining_seconds' => max(1, $timestamp - time()),
                'reason' => (string) ($row->reason ?? ''),
                'reason_key' => 'mute',
                'blackout' => self::buildBlackoutPayload(null),
            ];
        } catch (\Exception $e) {
            $state = [
                'is_muted' => false,
                'muted_until' => null,
                'remaining_seconds' => 0,
                'reason' => '',
                'reason_key' => '',
                'blackout' => self::buildBlackoutPayload(null),
            ];
            return self::appendBlackoutRestriction($uid, $state);
        }
    }

    private static function appendBlackoutRestriction($uid, array $state)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            return $state;
        }

        try {
            $countryId = self::resolveUserCountryId($uid);
            $blackout = self::getActiveBlackout($countryId);
            $state['blackout'] = self::buildBlackoutPayload($blackout);

            if (!$blackout || self::canPostDuringBlackout($uid, $countryId)) {
                return $state;
            }

            $expiresAt = !empty($blackout->expires_at) ? strtotime((string) $blackout->expires_at) : 0;
            if ($expiresAt <= time()) {
                return $state;
            }

            return [
                'is_muted' => true,
                'muted_until' => (string) ($blackout->expires_at ?? ''),
                'remaining_seconds' => max(1, $expiresAt - time()),
                'reason' => 'Medya Karartmasi aktif. Sadece baskan ve meclis uyeleri shout atabilir.',
                'reason_key' => 'media_blackout',
                'blackout' => self::buildBlackoutPayload($blackout),
            ];
        } catch (\Exception $e) {
            return $state;
        }
    }

    private static function humanRemaining($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . ' sn';
        }

        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' dk';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' saat';
        }

        $days = (int) floor($hours / 24);
        return $days . ' gun';
    }

    private static function relativeTimeLabel($value)
    {
        static $translator = null;

        $translate = function ($key, array $replace = []) use (&$translator) {
            try {
                if ($translator === null) {
                    $translator = \App\System\App::container()->get('i18n')->getTranslator();
                }

                return $translator->translate($key, $replace);
            } catch (\Exception $e) {
                static $fallback = null;
                if ($fallback === null) {
                    $fallback = require APP_ROOT . 'lang/tr.php';
                }
                $message = $fallback[$key] ?? $key;

                if (!empty($replace)) {
                    $replacements = [];
                    foreach ($replace as $replaceKey => $replaceValue) {
                        $replacements[':' . $replaceKey] = $replaceValue;
                    }
                    $message = strtr($message, $replacements);
                }

                return $message;
            }
        };

        if (empty($value)) {
            return 'az once';
        }

        $timestamp = strtotime((string) $value);
        if (!$timestamp) {
            return (string) $value;
        }

        $diff = time() - $timestamp;
        if ($diff < 0) {
            return date('d.m.Y H:i', $timestamp);
        }

        $minutes = (int) floor($diff / 60);
        if ($minutes <= 1) {
            return 'az once';
        }

        if ($minutes < 60) {
            return $minutes . ' ' . $translate('home.shouts.minutes_short') . ' once';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' ' . $translate('home.shouts.hours_short') . ' once';
        }

        $days = (int) floor($hours / 24);
        if ($days < 7) {
            return $days . ' ' . $translate('home.shouts.days_short') . ' once';
        }

        return date('d.m.Y H:i', $timestamp);
    }

    private function getReporterCooldownUntil($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1 || !$this->isSchemaReady()) {
            return null;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $row = DB::table('shout_reports')
                ->where('uid', $uid)
                ->where('created_at', '>=', $cutoff)
                ->selectRaw('COUNT(*) as total_reports, MAX(created_at) as last_reported_at')
                ->first();

            $totalReports = (int) ($row->total_reports ?? 0);
            $lastReportedAt = !empty($row->last_reported_at) ? strtotime((string) $row->last_reported_at) : false;

            if ($totalReports < self::REPORT_TOTAL_LIMIT || !$lastReportedAt) {
                return null;
            }

            $cooldownUntil = strtotime('+' . self::REPORT_TOTAL_COOLDOWN_HOURS . ' hours', $lastReportedAt);
            if ($cooldownUntil <= time()) {
                return null;
            }

            return $cooldownUntil;
        } catch (\Exception $e) {
            Logger::warning('Reporter shout cooldown check failed.', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function hasTargetReportSpam($reporterUid, $targetUid)
    {
        $reporterUid = (int) $reporterUid;
        $targetUid = (int) $targetUid;
        if ($reporterUid < 1 || $targetUid < 1 || !$this->isSchemaReady()) {
            return false;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::REPORT_TARGET_WINDOW_HOURS . ' hours'));
            $count = (int) DB::table('shout_reports as sr')
                ->join('shouts as s', 's.id', '=', 'sr.shout_id')
                ->where('sr.uid', $reporterUid)
                ->where('s.uid', $targetUid)
                ->where('sr.created_at', '>=', $cutoff)
                ->count();

            return $count >= self::REPORT_TARGET_LIMIT;
        } catch (\Exception $e) {
            Logger::warning('Targeted shout report spam check failed.', [
                'uid' => $reporterUid,
                'target_uid' => $targetUid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function hasTargetLikeSpam($likerUid, $targetUid)
    {
        $likerUid = (int) $likerUid;
        $targetUid = (int) $targetUid;
        if ($likerUid < 1 || $targetUid < 1 || !$this->isSchemaReady()) {
            return false;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::LIKE_TARGET_WINDOW_HOURS . ' hours'));
            $count = (int) DB::table('shout_likes as sl')
                ->join('shouts as s', 's.id', '=', 'sl.shout_id')
                ->where('sl.uid', $likerUid)
                ->where('s.uid', $targetUid)
                ->where('sl.created_at', '>=', $cutoff)
                ->count();

            return $count >= self::LIKE_TARGET_LIMIT;
        } catch (\Exception $e) {
            Logger::warning('Targeted shout like spam check failed.', [
                'uid' => $likerUid,
                'target_uid' => $targetUid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function notifyMentionedUsers($body, $authorUid, $shoutId, array $excludedUserIds = [])
    {
        $authorUid = (int) $authorUid;
        $shoutId = (int) $shoutId;
        if ($authorUid < 1 || $shoutId < 1) {
            return;
        }

        $body = self::stripArticleLinksFromBody($body);
        $mentions = self::resolveMentionUsers(self::extractMentionNicknames($body));
        if (empty($mentions)) {
            return;
        }

        $authorNick = (string) DB::table('users')->where('id', $authorUid)->value('nick');
        $threadShoutId = (int) DB::table('shouts')->where('id', $shoutId)->value('parent_id');
        if ($threadShoutId < 1) {
            $threadShoutId = $shoutId;
        }

        $homeUrl = $this->app->getContainer()->get('router')->pathFor('home') . '#shout-' . $threadShoutId;
        $excludedLookup = [];
        foreach ($excludedUserIds as $excludedUserId) {
            $excludedUserId = (int) $excludedUserId;
            if ($excludedUserId > 0) {
                $excludedLookup[$excludedUserId] = true;
            }
        }

        foreach ($mentions as $mentionUser) {
            $mentionedUid = (int) ($mentionUser['id'] ?? 0);
            if ($mentionedUid < 1 || $mentionedUid === $authorUid || isset($excludedLookup[$mentionedUid])) {
                continue;
            }

            Notify::push(
                $mentionedUid,
                'shout_mention',
                $this->translateForUser($mentionedUid, 'shout.notifications.mention.title'),
                $this->translateForUser($mentionedUid, 'shout.notifications.mention.body', [
                    'nick' => $authorNick,
                ]),
                $homeUrl,
                ['shout_id' => $shoutId, 'thread_shout_id' => $threadShoutId, 'from_uid' => $authorUid]
            );
        }
    }

    private function notifyReplyTarget($authorUid, $parentShout, $replyShoutId)
    {
        $authorUid = (int) $authorUid;
        $replyShoutId = (int) $replyShoutId;
        $parentShoutId = (int) ($parentShout->id ?? 0);
        $targetUid = (int) ($parentShout->uid ?? 0);

        if ($authorUid < 1 || $replyShoutId < 1 || $parentShoutId < 1 || $targetUid < 1 || $targetUid === $authorUid) {
            return;
        }

        $authorNick = (string) DB::table('users')->where('id', $authorUid)->value('nick');
        $homeUrl = $this->app->getContainer()->get('router')->pathFor('home') . '#shout-' . $parentShoutId;

        Notify::push(
            $targetUid,
            'shout_reply',
            $this->translateForUser($targetUid, 'shout.notifications.reply.title'),
            $this->translateForUser($targetUid, 'shout.notifications.reply.body', [
                'nick' => $authorNick,
            ]),
            $homeUrl,
            [
                'shout_id' => $replyShoutId,
                'thread_shout_id' => $parentShoutId,
                'parent_shout_id' => $parentShoutId,
                'from_uid' => $authorUid,
            ]
        );
    }

    private function buildShoutUrl($threadShoutId = 0)
    {
        $threadShoutId = (int) $threadShoutId;
        $url = $this->app->getContainer()->get('router')->pathFor('home');

        if ($threadShoutId > 0) {
            $url .= '#shout-' . $threadShoutId;
        }

        return $url;
    }

    private function hasShoutNotification($uid, $type, $shoutId)
    {
        $uid = (int) $uid;
        $shoutId = (int) $shoutId;
        $type = (string) $type;

        if ($uid < 1 || $shoutId < 1 || $type === '') {
            return false;
        }

        try {
            return DB::table('notifications')
                ->where('uid', $uid)
                ->where('type', $type)
                ->where(function ($query) use ($shoutId) {
                    $query->where('meta', 'like', '%"shout_id":' . $shoutId . ',%')
                        ->orWhere('meta', 'like', '%"shout_id":' . $shoutId . '}%');
                })
                ->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    private function pushTranslatedShoutNotification($targetUid, $type, $titleKey, $bodyKey, array $replace = [], $threadShoutId = 0, array $meta = [], $dedupeShoutId = 0)
    {
        $targetUid = (int) $targetUid;
        $threadShoutId = (int) $threadShoutId;
        $dedupeShoutId = (int) $dedupeShoutId;

        if ($targetUid < 1) {
            return false;
        }

        if ($dedupeShoutId > 0 && $this->hasShoutNotification($targetUid, $type, $dedupeShoutId)) {
            return false;
        }

        if ($dedupeShoutId > 0 && empty($meta['shout_id'])) {
            $meta['shout_id'] = $dedupeShoutId;
        }

        if ($threadShoutId > 0 && empty($meta['thread_shout_id'])) {
            $meta['thread_shout_id'] = $threadShoutId;
        }

        return Notify::push(
            $targetUid,
            (string) $type,
            $this->translateForUser($targetUid, $titleKey, $replace),
            $this->translateForUser($targetUid, $bodyKey, $replace),
            $this->buildShoutUrl($threadShoutId > 0 ? $threadShoutId : $dedupeShoutId),
            $meta
        );
    }

    private function resolveCountryCitizenIds($countryId, array $excludedUserIds = [])
    {
        $countryId = (int) $countryId;
        if ($countryId < 1) {
            return [];
        }

        $excludedUserIds = array_values(array_filter(array_map('intval', $excludedUserIds)));

        try {
            $query = DB::table('users as u')
                ->leftJoin('regions as r', 'r.id', '=', 'u.region')
                ->whereRaw('COALESCE(u.country_id, r.country, 0) = ?', [$countryId]);

            if (!empty($excludedUserIds)) {
                $query->whereNotIn('u.id', $excludedUserIds);
            }

            $rows = $query->get(['u.id']);
            $userIds = [];
            foreach ($rows as $row) {
                $userId = (int) ($row->id ?? 0);
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }

            return array_values(array_unique($userIds));
        } catch (\Exception $e) {
            return [];
        }
    }

    private function loadShoutNotificationContext($shoutId)
    {
        $shoutId = (int) $shoutId;
        if ($shoutId < 1) {
            return null;
        }

        try {
            $row = DB::table('shouts as s')
                ->leftJoin('users as u', 'u.id', '=', 's.uid')
                ->leftJoin('regions as ur', 'ur.id', '=', 'u.region')
                ->where('s.id', $shoutId)
                ->where('s.is_deleted', 0)
                ->first([
                    's.id',
                    's.uid',
                    's.parent_id',
                    's.likes_count',
                    's.tips_gold_total',
                    's.reports_count',
                    's.is_state_decree',
                    's.decree_country_id',
                    'u.nick',
                    DB::raw('COALESCE(u.country_id, ur.country, 0) as author_country_id'),
                ]);

            if (!$row) {
                return null;
            }

            $threadShoutId = (int) ($row->parent_id ?? 0);
            if ($threadShoutId < 1) {
                $threadShoutId = (int) ($row->id ?? 0);
            }

            $countryId = (int) ($row->is_state_decree ?? 0) === 1
                ? (int) ($row->decree_country_id ?? 0)
                : (int) ($row->author_country_id ?? 0);

            $replyCount = 0;
            if ((int) ($row->parent_id ?? 0) < 1) {
                $replyCount = (int) DB::table('shouts')
                    ->where('parent_id', (int) ($row->id ?? 0))
                    ->where('is_deleted', 0)
                    ->count();
            }

            return [
                'id' => (int) ($row->id ?? 0),
                'uid' => (int) ($row->uid ?? 0),
                'parent_id' => (int) ($row->parent_id ?? 0),
                'thread_shout_id' => $threadShoutId,
                'likes_count' => (int) ($row->likes_count ?? 0),
                'tips_gold_total' => (float) ($row->tips_gold_total ?? 0),
                'reports_count' => (int) ($row->reports_count ?? 0),
                'reply_count' => $replyCount,
                'is_state_decree' => (int) ($row->is_state_decree ?? 0) === 1,
                'country_id' => $countryId,
                'nick' => (string) ($row->nick ?? ''),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function computeInteractionScore(array $context)
    {
        $likes = (int) ($context['likes_count'] ?? 0);
        $replies = (int) ($context['reply_count'] ?? 0);
        $reports = (int) ($context['reports_count'] ?? 0);
        $tips = min((float) ($context['tips_gold_total'] ?? 0), 50.0);

        return ($likes * 2) + ($replies * 3) + ($tips * 0.35) - ($reports * 4);
    }

    private function computeCriticalSignal(array $context)
    {
        $likes = (int) ($context['likes_count'] ?? 0);
        $replies = (int) ($context['reply_count'] ?? 0);
        $tips = min((float) ($context['tips_gold_total'] ?? 0), 50.0);

        return ($likes * 2) + ($replies * 3) + ($tips * 0.35);
    }

    private function notifyReportedTarget($reporterUid, $shout, $reportCount = 0)
    {
        $reporterUid = (int) $reporterUid;
        $targetUid = (int) ($shout->uid ?? 0);
        $shoutId = (int) ($shout->id ?? 0);
        $threadShoutId = (int) ($shout->parent_id ?? 0);
        $reportCount = max(1, (int) $reportCount);

        if ($threadShoutId < 1) {
            $threadShoutId = $shoutId;
        }

        if ($reporterUid < 1 || $targetUid < 1 || $shoutId < 1 || $targetUid === $reporterUid) {
            return;
        }

        $reporterNick = (string) DB::table('users')->where('id', $reporterUid)->value('nick');

        $this->pushTranslatedShoutNotification(
            $targetUid,
            'shout_reported',
            'shout.notifications.reported.title',
            'shout.notifications.reported.body',
            [
                'nick' => $reporterNick,
                'count' => $reportCount,
            ],
            $threadShoutId,
            [
                'shout_id' => $shoutId,
                'thread_shout_id' => $threadShoutId,
                'from_uid' => $reporterUid,
                'report_count' => $reportCount,
            ],
            $shoutId
        );
    }

    private function notifyStateDecreePublished($authorUid, $shoutId, $countryId)
    {
        $authorUid = (int) $authorUid;
        $shoutId = (int) $shoutId;
        $countryId = (int) $countryId;
        if ($authorUid < 1 || $shoutId < 1 || $countryId < 1) {
            return;
        }

        $authorNick = (string) DB::table('users')->where('id', $authorUid)->value('nick');
        $recipientIds = $this->resolveCountryCitizenIds($countryId, [$authorUid]);

        foreach ($recipientIds as $recipientUid) {
            $this->pushTranslatedShoutNotification(
                $recipientUid,
                'state_decree',
                'shout.notifications.state_decree.title',
                'shout.notifications.state_decree.body',
                ['nick' => $authorNick],
                $shoutId,
                [
                    'shout_id' => $shoutId,
                    'thread_shout_id' => $shoutId,
                    'from_uid' => $authorUid,
                    'country_id' => $countryId,
                ],
                $shoutId
            );
        }
    }

    private function maybeNotifyCountryCriticalShout($shoutId, $triggerUid = 0, array $context = null)
    {
        $triggerUid = (int) $triggerUid;
        $context = $context ?: $this->loadShoutNotificationContext($shoutId);

        if (!$context) {
            return;
        }

        $shoutId = (int) ($context['id'] ?? 0);
        $countryId = (int) ($context['country_id'] ?? 0);
        $authorUid = (int) ($context['uid'] ?? 0);

        if (
            $shoutId < 1 ||
            $countryId < 1 ||
            $authorUid < 1 ||
            !empty($context['parent_id']) ||
            !empty($context['is_state_decree'])
        ) {
            return;
        }

        $criticalSignal = $this->computeCriticalSignal($context);
        $reportCount = (int) ($context['reports_count'] ?? 0);
        if ($criticalSignal < self::COUNTRY_CRITICAL_SCORE && $reportCount < self::COUNTRY_CRITICAL_REPORTS) {
            return;
        }

        $recipientIds = $this->resolveCountryCitizenIds($countryId, [$authorUid, $triggerUid]);
        foreach ($recipientIds as $recipientUid) {
            $this->pushTranslatedShoutNotification(
                $recipientUid,
                'country_critical_shout',
                'shout.notifications.country_critical.title',
                'shout.notifications.country_critical.body',
                ['nick' => (string) ($context['nick'] ?? '')],
                (int) ($context['thread_shout_id'] ?? $shoutId),
                [
                    'shout_id' => $shoutId,
                    'thread_shout_id' => (int) ($context['thread_shout_id'] ?? $shoutId),
                    'from_uid' => $authorUid,
                    'country_id' => $countryId,
                    'trigger_uid' => $triggerUid,
                ],
                $shoutId
            );
        }
    }

    private function maybeNotifyTrending($shoutId, $triggerUid = 0)
    {
        $triggerUid = (int) $triggerUid;
        $context = $this->loadShoutNotificationContext($shoutId);
        if (!$context) {
            return;
        }

        $authorUid = (int) ($context['uid'] ?? 0);
        $shoutId = (int) ($context['id'] ?? 0);
        $likes = (int) ($context['likes_count'] ?? 0);
        $replies = (int) ($context['reply_count'] ?? 0);
        $tips = (float) ($context['tips_gold_total'] ?? 0);

        if (
            $shoutId < 1 ||
            $authorUid < 1 ||
            $authorUid === $triggerUid ||
            !empty($context['parent_id']) ||
            !empty($context['is_state_decree']) ||
            ($likes < 1 && $replies < 1 && $tips <= 0)
        ) {
            return;
        }

        $score = $this->computeInteractionScore($context);
        if ($score < self::TREND_NOTIFICATION_SCORE) {
            return;
        }

        $this->pushTranslatedShoutNotification(
            $authorUid,
            'shout_trending',
            'shout.notifications.trending.title',
            'shout.notifications.trending.body',
            [],
            (int) ($context['thread_shout_id'] ?? $shoutId),
            [
                'shout_id' => $shoutId,
                'thread_shout_id' => (int) ($context['thread_shout_id'] ?? $shoutId),
                'trigger_uid' => $triggerUid,
                'score' => round($score, 2),
            ],
            $shoutId
        );

        $this->maybeNotifyCountryCriticalShout($shoutId, $triggerUid, $context);
    }

    private function maybeAutoHideReportedShout($shoutId, $reportCount = 0)
    {
        $shoutId = (int) $shoutId;
        $reportCount = (int) $reportCount;

        if ($shoutId < 1 || $reportCount < self::AUTO_HIDE_REPORT_THRESHOLD || !$this->isSchemaReady()) {
            return false;
        }

        try {
            $updated = DB::table('shouts')
                ->where('id', $shoutId)
                ->where('is_deleted', 0)
                ->update([
                    'is_deleted' => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return (int) $updated > 0;
        } catch (\Exception $e) {
            Logger::warning('Automatic shout hide failed.', [
                'shout_id' => $shoutId,
                'report_count' => $reportCount,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function applyAutomaticRestriction($uid, $reasonKey = 'report')
    {
        $uid = (int) $uid;
        if ($uid < 1 || !self::hasRestrictionsTable() || !$this->isSchemaReady()) {
            return;
        }

        if ($this->currentUserIsAdmin() || self::currentUserIsAdminStatic($uid)) {
            return;
        }

        try {
            $shouldRestrict = false;
            $muteHours = 0;
            $reason = '';

            if ($reasonKey === 'report') {
                $reportCutoff = date('Y-m-d H:i:s', strtotime('-72 hours'));
                $reportTotal = (int) DB::table('shouts')
                    ->where('uid', $uid)
                    ->where('created_at', '>=', $reportCutoff)
                    ->sum('reports_count');

                if ($reportTotal >= self::AUTO_REPORT_RESTRICTION_THRESHOLD) {
                    $shouldRestrict = true;
                    $muteHours = 3;
                    $reason = 'Cok fazla rapor alan shout davranisi';
                }
            }

            if (!$shouldRestrict) {
                $deleteCutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
                $deleteCount = (int) DB::table('shouts')
                    ->where('uid', $uid)
                    ->where('is_deleted', 1)
                    ->where('updated_at', '>=', $deleteCutoff)
                    ->count();

                if ($deleteCount >= self::AUTO_DELETE_RESTRICTION_THRESHOLD) {
                    $shouldRestrict = true;
                    $muteHours = 1;
                    $reason = 'Cok fazla kaldirilan shout davranisi';
                }
            }

            if (!$shouldRestrict) {
                return;
            }

            $mutedUntil = date('Y-m-d H:i:s', strtotime('+' . $muteHours . ' hours'));
            $existing = DB::table('shout_user_restrictions')->where('uid', $uid)->first();

            if ($existing) {
                $existingUntil = !empty($existing->muted_until) ? strtotime((string) $existing->muted_until) : 0;
                if ($existingUntil >= strtotime($mutedUntil)) {
                    return;
                }

                DB::table('shout_user_restrictions')
                    ->where('uid', $uid)
                    ->update([
                        'muted_until' => $mutedUntil,
                        'reason' => $reason,
                        'created_by' => null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                DB::table('shout_user_restrictions')->insert([
                    'uid' => $uid,
                    'muted_until' => $mutedUntil,
                    'reason' => $reason,
                    'created_by' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            Notify::push(
                $uid,
                'shout_restriction',
                'Shout paylasimin gecici olarak kisitlandi',
                $reason . ' nedeniyle ' . self::humanRemaining(strtotime($mutedUntil) - time()) . ' boyunca shout atamayacaksin.',
                $this->app->getContainer()->get('router')->pathFor('home'),
                ['muted_until' => $mutedUntil]
            );
        } catch (\Exception $e) {
            Logger::warning('Automatic shout restriction failed.', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function normalizeMode($mode, $isAdmin = false)
    {
        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['latest', 'popular', 'reported', 'my-country'], true)) {
            $mode = 'latest';
        }

        if ($mode === 'reported' && !$isAdmin) {
            return 'latest';
        }

        return $mode;
    }

    private static function baseFeedQuery($viewerUid = 0, $includeDeleted = false)
    {
        $viewerUid = (int) $viewerUid;

        $query = DB::table('shouts as s')
            ->leftJoin('users as u', 'u.id', '=', 's.uid')
            ->leftJoin('regions as ur', 'ur.id', '=', 'u.region')
            ->leftJoin(DB::raw('(SELECT uid, MIN(party) AS party_id, MAX(level) AS level FROM party_members GROUP BY uid) pm'), 'pm.uid', '=', 's.uid')
            ->leftJoin('political_parties as p', 'p.id', '=', 'pm.party_id')
            ->leftJoin(DB::raw('(SELECT president, MIN(name) AS president_country_name FROM countries WHERE president IS NOT NULL GROUP BY president) cp'), 'cp.president', '=', 's.uid')
            ->leftJoin('shout_likes as sl', function ($join) use ($viewerUid) {
                $join->on('sl.shout_id', '=', 's.id');
                if ($viewerUid > 0) {
                    $join->where('sl.uid', '=', $viewerUid);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->leftJoin('shout_reports as sr', function ($join) use ($viewerUid) {
                $join->on('sr.shout_id', '=', 's.id');
                if ($viewerUid > 0) {
                    $join->where('sr.uid', '=', $viewerUid);
                } else {
                    $join->whereRaw('1 = 0');
                }
              });

        if (!$includeDeleted) {
            $query->where('s.is_deleted', 0);
        }

        return $query;
    }

    private static function applyPopularOrdering($query)
    {
        try {
            $scoreParts = [
                '(COALESCE(s.likes_count, 0) * 2)',
            ];

            if (self::hasColumnCached('shouts', 'parent_id')) {
                $scoreParts[] = '((SELECT COUNT(*) FROM shouts r WHERE r.parent_id = s.id AND r.is_deleted = 0) * 3)';
            }

            if (self::hasColumnCached('shouts', 'tips_gold_total')) {
                $scoreParts[] = '(LEAST(COALESCE(s.tips_gold_total, 0), 50) * 0.35)';
            }

            if (self::hasColumnCached('shouts', 'reports_count')) {
                $scoreParts[] = '(COALESCE(s.reports_count, 0) * -4)';
            }

            $scoreParts[] = '(GREATEST(0, 48 - TIMESTAMPDIFF(HOUR, s.created_at, NOW())) * 0.25)';

            $query->orderByRaw('(' . implode(' + ', $scoreParts) . ') DESC');
            $query->orderBy('s.likes_count', 'desc');
            $query->orderBy('s.id', 'desc');

            return true;
        } catch (\Exception $e) {
            Logger::warning('Popular shout ordering fallback activated.', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function mapFeedRowsToItems($rows, $viewerUid = 0)
    {
        $viewerUid = (int) $viewerUid;
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) ($row->id ?? 0),
                'uid' => (int) ($row->uid ?? 0),
                'parent_id' => (int) ($row->parent_id ?? 0),
                'body' => (string) ($row->body ?? ''),
                'has_poll' => (int) ($row->has_poll ?? 0) === 1,
                'poll_question' => (string) ($row->poll_question ?? ''),
                'poll_data' => (string) ($row->poll_data ?? ''),
                'poll_total_votes' => (int) ($row->poll_total_votes ?? 0),
                'poll_duration_hours' => (int) ($row->poll_duration_hours ?? 0),
                'poll_expires_at' => (string) ($row->poll_expires_at ?? ''),
                'poll_cost_gold' => round((float) ($row->poll_cost_gold ?? 0), 2),
                'likes_count' => (int) ($row->likes_count ?? 0),
                'tips_gold_total' => round((float) ($row->tips_gold_total ?? 0), 2),
                'reports_count' => (int) ($row->reports_count ?? 0),
                'is_hidden' => (int) ($row->is_deleted ?? 0) === 1,
                'is_state_decree' => (int) ($row->is_state_decree ?? 0) === 1,
                'decree_country_id' => (int) ($row->decree_country_id ?? 0),
                'decree_expires_at' => (string) ($row->decree_expires_at ?? ''),
                'decree_cost_currency' => (string) ($row->decree_cost_currency ?? ''),
                'decree_cost_amount' => round((float) ($row->decree_cost_amount ?? 0), 2),
                'article_card_article_id' => (int) ($row->article_card_article_id ?? 0),
                'created_at' => (string) ($row->created_at ?? ''),
                'created_at_label' => self::relativeTimeLabel($row->created_at ?? ''),
                'edited_at' => (string) ($row->edited_at ?? ''),
                'nick' => (string) ($row->nick ?? 'Vatandas'),
                'avatar' => (string) ($row->avatar ?? ''),
                'country_id' => (int) ($row->effective_country_id ?? 0),
                'media_reputation' => (int) ($row->media_reputation ?? 0),
                'party_level' => (int) ($row->party_level ?? 0),
                'party_id' => (int) ($row->party_id ?? 0),
                'party_name' => (string) ($row->party_name ?? ''),
                'party_logo_url' => (string) ($row->party_logo_url ?? ''),
                'is_party_leader' => ((int) ($row->party_level ?? 0) === 3),
                'is_country_president' => !empty($row->president_country_name),
                'president_country_name' => (string) ($row->president_country_name ?? ''),
                'can_edit' => (int) ($row->uid ?? 0) === $viewerUid && !empty($row->created_at) && (strtotime((string) $row->created_at) >= strtotime('-' . self::EDIT_WINDOW_MINUTES . ' minutes')),
                'liked_by_me' => (int) ($row->liked_by_me ?? 0) === 1,
                'reported_by_me' => (int) ($row->reported_by_me ?? 0) === 1,
            ];
        }

        return $items;
    }

    private static function feedSelectColumns($hasEditedAt = true, $hasMediaReputation = true)
    {
        return [
            's.id',
            's.uid',
            's.parent_id',
            's.body',
            's.has_poll',
            's.poll_question',
            's.poll_data',
            's.poll_total_votes',
            's.poll_duration_hours',
            's.poll_expires_at',
            's.poll_cost_gold',
            's.likes_count',
            's.tips_gold_total',
            's.reports_count',
            's.is_deleted',
            's.is_state_decree',
            's.decree_country_id',
            's.decree_expires_at',
            's.decree_cost_currency',
            's.decree_cost_amount',
            's.article_card_article_id',
            's.created_at',
            $hasEditedAt ? 's.edited_at' : DB::raw('NULL as edited_at'),
            'u.nick',
            'u.avatar',
            DB::raw('COALESCE(u.country_id, ur.country, 0) as effective_country_id'),
            $hasMediaReputation ? 'u.media_reputation' : DB::raw('0 as media_reputation'),
            'pm.level as party_level',
            'p.id as party_id',
            'p.name as party_name',
            'p.logo_url as party_logo_url',
            'cp.president_country_name',
            DB::raw('CASE WHEN sl.id IS NULL THEN 0 ELSE 1 END as liked_by_me'),
            DB::raw('CASE WHEN sr.id IS NULL THEN 0 ELSE 1 END as reported_by_me'),
        ];
    }

    private static function attachRepliesToFeedItems(array $items, $viewerUid = 0, $isAdmin = false)
    {
        if (empty($items)) {
            return $items;
        }

        $parentIds = array_values(array_filter(array_map(function ($item) {
            return (int) ($item['id'] ?? 0);
        }, $items)));

        if (empty($parentIds)) {
            return $items;
        }

        $hasEditedAt = self::hasColumnCached('shouts', 'edited_at');
        $hasMediaReputation = self::hasColumnCached('users', 'media_reputation');

        $replyRows = self::baseFeedQuery($viewerUid)
            ->whereIn('s.parent_id', $parentIds)
            ->orderBy('s.parent_id', 'asc')
            ->orderBy('s.id', 'asc')
            ->get(self::feedSelectColumns($hasEditedAt, $hasMediaReputation));

        if ($replyRows->isEmpty()) {
            foreach ($items as &$item) {
                $item['reply_count'] = 0;
                $item['reply_preview_limit'] = self::REPLY_PREVIEW_LIMIT;
                $item['replies'] = [];
            }
            unset($item);
            return $items;
        }

        $replyItems = self::mapFeedRowsToItems($replyRows, $viewerUid);
        $replyItems = self::attachFeedExtras($replyItems, $viewerUid, $isAdmin);

        $replyMap = [];
        foreach ($replyItems as $replyItem) {
            $parentId = (int) ($replyItem['parent_id'] ?? 0);
            if ($parentId < 1) {
                continue;
            }
            if (!isset($replyMap[$parentId])) {
                $replyMap[$parentId] = [];
            }
            $replyMap[$parentId][] = $replyItem;
        }

        foreach ($items as &$item) {
            $itemId = (int) ($item['id'] ?? 0);
            $itemReplies = $replyMap[$itemId] ?? [];
            $item['reply_count'] = count($itemReplies);
            $item['reply_preview_limit'] = self::REPLY_PREVIEW_LIMIT;
            $item['replies'] = $itemReplies;
        }
        unset($item);

        return $items;
    }

    public static function fetchFeedSlice($viewerUid = 0, $limit = self::DEFAULT_PAGE_SIZE, $offset = 0, $mode = 'latest', $isAdmin = false, $page = null)
    {
        $viewerUid = (int) $viewerUid;
        $limit = max(1, min(20, (int) $limit));
        $page = max(1, (int) ($page ?: 1));
        $offset = max(0, (int) $offset);
        if ($page > 1) {
            $offset = ($page - 1) * $limit;
        } else {
            $page = (int) floor($offset / max(1, $limit)) + 1;
        }
        $mode = self::normalizeMode($mode, $isAdmin);
        $hasEditedAt = self::hasColumnCached('shouts', 'edited_at');
        $hasMediaReputation = self::hasColumnCached('users', 'media_reputation');
        $includeDeleted = $mode === 'reported' && $isAdmin;

        $query = self::baseFeedQuery($viewerUid, $includeDeleted);
        $query->whereNull('s.parent_id');
        $query->where(function ($query) {
            $query->where('s.is_state_decree', 0)
                ->orWhereNull('s.decree_expires_at')
                ->orWhere('s.decree_expires_at', '<=', date('Y-m-d H:i:s'));
        });

        if ($mode === 'my-country') {
            $viewerCountryId = self::resolveUserCountryId($viewerUid);
            if ($viewerCountryId > 0) {
                $query->whereRaw('COALESCE(u.country_id, ur.country, 0) = ?', [$viewerCountryId]);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $countQuery = clone $query;
        $popularFallbackQuery = null;
        if ($mode === 'popular') {
            $popularFallbackQuery = clone $query;
            if (!self::applyPopularOrdering($query)) {
                $query->orderBy('s.likes_count', 'desc')
                    ->orderBy('s.id', 'desc');
            }
        } elseif ($mode === 'reported') {
            $query->where('s.reports_count', '>', 0)
                ->orderBy('s.reports_count', 'desc')
                ->orderBy('s.id', 'desc');
        } else {
            $query->orderBy('s.id', 'desc');
        }

        $totalItems = 0;
        try {
            $totalItems = (int) $countQuery->count('s.id');
        } catch (\Exception $e) {
            $totalItems = 0;
        }

        try {
            $rows = $query
                ->offset($offset)
                ->limit($limit + 1)
                ->get(self::feedSelectColumns($hasEditedAt, $hasMediaReputation));
        } catch (\Exception $e) {
            if ($mode !== 'popular' || !$popularFallbackQuery) {
                throw $e;
            }

            Logger::warning('Popular shout query execution failed. Falling back to legacy ordering.', [
                'message' => $e->getMessage(),
            ]);

            $rows = $popularFallbackQuery
                ->orderBy('s.likes_count', 'desc')
                ->orderBy('s.id', 'desc')
                ->offset($offset)
                ->limit($limit + 1)
                ->get(self::feedSelectColumns($hasEditedAt, $hasMediaReputation));
        }

        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $items = self::mapFeedRowsToItems($rows, $viewerUid);
        $items = self::attachFeedExtras($items, $viewerUid, $isAdmin);
        $items = self::attachRepliesToFeedItems($items, $viewerUid, $isAdmin);

        return [
            'mode' => $mode,
            'items' => $items,
            'total' => $totalItems,
            'total_items' => $totalItems,
            'page_size' => $limit,
            'current_page' => $page,
            'total_pages' => max(1, (int) ceil($totalItems / max(1, $limit))),
            'has_more' => $hasMore,
            'next_offset' => $offset + count($items),
            'reported_total' => $isAdmin ? (int) DB::table('shouts')->where('reports_count', '>', 0)->count() : 0,
            'blackout' => $viewerUid > 0 ? self::buildBlackoutPayload(self::getActiveBlackout(self::resolveUserCountryId($viewerUid))) : self::buildBlackoutPayload(null),
        ];
    }

    public static function fetchThreadItemById($shoutId = 0, $viewerUid = 0, $isAdmin = false)
    {
        $shoutId = (int) $shoutId;
        $viewerUid = (int) $viewerUid;

        if ($shoutId < 1) {
            return null;
        }

        $hasEditedAt = self::hasColumnCached('shouts', 'edited_at');
        $hasMediaReputation = self::hasColumnCached('users', 'media_reputation');

        $row = self::baseFeedQuery($viewerUid)
            ->where('s.id', $shoutId)
            ->whereNull('s.parent_id')
            ->first(self::feedSelectColumns($hasEditedAt, $hasMediaReputation));

        if (!$row) {
            return null;
        }

        $items = self::mapFeedRowsToItems([$row], $viewerUid);
        $items = self::attachFeedExtras($items, $viewerUid, $isAdmin);
        $items = self::attachRepliesToFeedItems($items, $viewerUid, $isAdmin);

        return !empty($items) ? $items[0] : null;
    }

    private function feedPayload($mode = 'latest', $offset = 0, $limit = self::DEFAULT_PAGE_SIZE, $page = null)
    {
        return self::fetchFeedSlice(
            $this->currentUserId(),
            $limit,
            $offset,
            $mode,
            $this->currentUserIsAdmin(),
            $page
        );
    }

    public static function getComposerMeta($uid)
    {
        $uid = (int) $uid;
        $country = null;

        try {
            $country = DB::table('countries')
                ->where('president', $uid)
                ->first(['id', 'name', 'currency']);
        } catch (\Exception $e) {
            $country = null;
        }

        return [
            'can_create_poll' => true,
            'can_create_state_decree' => !empty($country),
            'decree_country_id' => (int) ($country->id ?? 0),
            'decree_country_name' => (string) ($country->name ?? ''),
            'decree_cost_currency' => self::DECREE_COST_CURRENCY,
            'decree_cost_amount' => self::DECREE_COST_AMOUNT,
            'decree_duration_hours' => self::DECREE_DURATION_HOURS,
            'blackout' => self::getRestrictionState($uid)['blackout'] ?? self::buildBlackoutPayload(null),
        ];
    }

    public function listFeed()
    {
        try {
            $uid = $this->currentUserId();
            if ($uid < 1) {
                return ['error' => true, 'message' => 'Oturum bulunamadi.'];
            }

            if (!$this->isSchemaReady()) {
                return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
            }

            if (!self::featuresSchemaReady()) {
                return ['error' => true, 'message' => 'Shout ozellikleri henuz hazir degil.'];
            }

            $mode = $_POST['mode'] ?? 'latest';
            $offset = (int) ($_POST['offset'] ?? 0);
            $page = max(1, (int) ($_POST['page'] ?? 1));

            return [
                'error' => false,
                'feed' => $this->feedPayload($mode, $offset, self::DEFAULT_PAGE_SIZE, $page),
                'restriction' => self::getRestrictionState($uid),
            ];
        } catch (\Exception $e) {
            Logger::warning('Shout feed load failed.', [
                'uid' => $this->currentUserId(),
                'mode' => $_POST['mode'] ?? 'latest',
                'offset' => (int) ($_POST['offset'] ?? 0),
                'message' => $e->getMessage(),
            ]);

            return [
                'error' => true,
                'message' => 'Shout akisi su an yuklenemedi.',
            ];
        }
    }

    public function thread()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        try {
            $uid = $this->currentUserId();
            $shoutId = (int) ($_POST['shout_id'] ?? 0);

            if ($uid < 1 || $shoutId < 1) {
                return ['error' => true, 'message' => 'Gecersiz shout secildi.'];
            }

            if (!$this->isSchemaReady()) {
                return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
            }

            if (!self::featuresSchemaReady()) {
                return ['error' => true, 'message' => 'Shout ozellikleri henuz hazir degil.'];
            }

            $item = self::fetchThreadItemById($shoutId, $uid, $this->currentUserIsAdmin());
            if (!$item) {
                return ['error' => true, 'message' => 'Shout bulunamadi.'];
            }

            return [
                'error' => false,
                'shout' => $item,
            ];
        } catch (\Exception $e) {
            Logger::warning('Shout thread load failed.', [
                'uid' => $this->currentUserId(),
                'shout_id' => (int) ($_POST['shout_id'] ?? 0),
                'message' => $e->getMessage(),
            ]);

            return [
                'error' => true,
                'message' => 'Shout listesi su an yuklenemedi.',
            ];
        }
    }

    public function create()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        if ($uid < 1) {
            return ['error' => true, 'message' => 'Oturum bulunamadi.'];
        }

        if (!$this->isSchemaReady()) {
            return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
        }

        if (!$this->isFeatureSchemaReady()) {
            return ['error' => true, 'message' => 'Shout ozellikleri henuz hazir degil.'];
        }

        $body = $this->normalizeBody($_POST['body'] ?? '');
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $isReply = $parentId > 0;
        $length = function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body);
        $hasPoll = !$isReply && (int) ($_POST['has_poll'] ?? 0) === 1;
        $pollQuestion = $this->normalizeBody($_POST['poll_question'] ?? '');
        $pollDurationHours = 0;
        $pollCostGold = 0;
        $isStateDecree = !$isReply && (int) ($_POST['is_state_decree'] ?? 0) === 1;
        $pollOptions = [];
        $presidentCountry = null;
        $articleCardArticleId = $this->detectArticleCardArticleId($body);
        $linkCheckBody = $articleCardArticleId > 0 ? self::stripArticleLinksFromBody($body) : $body;
        $parentShout = null;

        if ($length < self::MIN_BODY_LENGTH) {
            return ['error' => true, 'message' => 'Shout en az 3 karakter olmali.'];
        }

        if ($length > self::MAX_BODY_LENGTH) {
            return ['error' => true, 'message' => 'Shout en fazla 240 karakter olabilir.'];
        }

        if ($this->containsBlockedLink($linkCheckBody)) {
            return ['error' => true, 'message' => 'Shout icinde link paylasimi su an kapali.'];
        }

        if ($this->containsBlockedTerm($body)) {
            return ['error' => true, 'message' => 'Shout icinde kullanilamayan kelimeler var.'];
        }

        if ($this->isLowQualityBody($body)) {
            return ['error' => true, 'message' => 'Shout daha acik ve anlamli olmali.'];
        }

        if ($this->looksLikeCopyPasteSpam($uid, $body)) {
            return ['error' => true, 'message' => 'Benzer shoutlari arka arkaya paylasamazsin.'];
        }

        $parentShout = null;
        if ($isReply) {
            $parentShout = DB::table('shouts')
                ->where('id', $parentId)
                ->where('is_deleted', 0)
                ->whereNull('parent_id')
                ->first(['id', 'uid']);

            if (!$parentShout) {
                return ['error' => true, 'message' => 'Yanitlanacak shout bulunamadi.'];
            }
        }

        if ($hasPoll) {
            try {
                $pollOptions = $this->parsePollOptions();
                $pollDurationHours = $this->parsePollDurationHours();
                $pollCostGold = (int) (self::POLL_DURATION_COSTS[$pollDurationHours] ?? 0);
            } catch (\Exception $e) {
                return ['error' => true, 'message' => $e->getMessage()];
            }

            if (empty($pollOptions)) {
                return ['error' => true, 'message' => 'Anket icin en az 2 secenek girmelisin.'];
            }

            if ($pollQuestion !== '') {
                $questionLength = function_exists('mb_strlen') ? mb_strlen($pollQuestion, 'UTF-8') : strlen($pollQuestion);
                if ($questionLength > 160) {
                    return ['error' => true, 'message' => 'Anket basligi en fazla 160 karakter olabilir.'];
                }
            }
        }

        if ($isStateDecree) {
            $presidentCountry = $this->getPresidentCountry($uid);
            if (!$presidentCountry || (int) ($presidentCountry->id ?? 0) < 1) {
                return ['error' => true, 'message' => 'Resmi duyuru sadece baskan tarafindan kullanilabilir.'];
            }
        }

        $cooldownUntil = $this->getPostingCooldownUntil($uid);
        if ($cooldownUntil) {
            $remainingSeconds = max(1, $cooldownUntil - time());
            $restrictionState = self::getRestrictionState($uid);
            return [
                'error' => true,
                'message' => !empty($restrictionState['reason']) ? (string) $restrictionState['reason'] : 'Shout paylasimi gecici olarak kisitlandi.',
                'retry_after' => $remainingSeconds,
                'restriction' => $restrictionState + [
                    'is_muted' => true,
                    'remaining_seconds' => $remainingSeconds,
                    'remaining_label' => self::humanRemaining($remainingSeconds),
                ],
            ];
        }

        $postingLimit = $this->getPostingLimitViolation($uid);
        if ($postingLimit) {
            return [
                'error' => true,
                'message' => $postingLimit['message'],
                'retry_after' => (int) ($postingLimit['retry_after'] ?? 0),
            ];
        }

        try {
            $lastSame = DB::table('shouts')
                ->where('uid', $uid)
                ->where('body', $body)
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-12 hours')))
                ->where('is_deleted', 0)
                ->when($isReply, function ($query) use ($parentId) {
                    return $query->where('parent_id', $parentId);
                }, function ($query) {
                    return $query->whereNull('parent_id');
                })
                ->first();

            if ($lastSame) {
                return ['error' => true, 'message' => 'Ayni shoutu tekrar paylasamazsin.'];
            }

            DB::beginTransaction();

            $decreeCountryId = $isStateDecree ? (int) ($presidentCountry->id ?? 0) : null;
            $decreeExpiresAt = $isStateDecree ? date('Y-m-d H:i:s', strtotime('+' . self::DECREE_DURATION_HOURS . ' hours')) : null;
            $now = date('Y-m-d H:i:s');
            $pollData = $hasPoll ? ShoutModel::encodePollData($pollOptions) : null;
            $pollExpiresAt = $hasPoll ? date('Y-m-d H:i:s', strtotime('+' . $pollDurationHours . ' hours')) : null;
            if ($isStateDecree && $decreeCountryId > 0) {
                DB::table('shouts')
                    ->where('is_state_decree', 1)
                    ->where('decree_country_id', $decreeCountryId)
                    ->where('is_deleted', 0)
                    ->where('decree_expires_at', '>', $now)
                    ->update([
                        'decree_expires_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            if ($hasPoll && $pollCostGold > 0) {
                $authorMoney = DB::table('user_money')->where('uid', $uid)->lockForUpdate()->first();
                if (!$authorMoney) {
                    DB::table('user_money')->insert([
                        'uid' => $uid,
                        'gold' => 0,
                    ]);
                    $authorMoney = DB::table('user_money')->where('uid', $uid)->lockForUpdate()->first();
                }

                if (!$authorMoney || (float) ($authorMoney->gold ?? 0) < (float) $pollCostGold) {
                    DB::rollBack();
                    return ['error' => true, 'message' => 'Bu anket suresi icin yeterli Gold bakiyen yok.'];
                }

                DB::table('user_money')->where('uid', $uid)->update([
                    'gold' => round((float) ($authorMoney->gold ?? 0) - (float) $pollCostGold, 2),
                ]);
            }

            $shoutId = DB::table('shouts')->insertGetId([
                'uid' => $uid,
                'parent_id' => $isReply ? $parentId : null,
                'body' => $body,
                'has_poll' => $hasPoll ? 1 : 0,
                'poll_question' => $hasPoll && $pollQuestion !== '' ? $pollQuestion : null,
                'poll_data' => $pollData,
                'poll_total_votes' => 0,
                'poll_duration_hours' => $hasPoll ? $pollDurationHours : 0,
                'poll_expires_at' => $pollExpiresAt,
                'poll_cost_gold' => $hasPoll ? $pollCostGold : 0,
                'likes_count' => 0,
                'tips_gold_total' => 0,
                'reports_count' => 0,
                'is_state_decree' => $isStateDecree ? 1 : 0,
                'decree_country_id' => $decreeCountryId,
                'decree_expires_at' => $decreeExpiresAt,
                'decree_cost_currency' => $isStateDecree ? self::DECREE_COST_CURRENCY : null,
                'decree_cost_amount' => $isStateDecree ? self::DECREE_COST_AMOUNT : 0,
                'article_card_article_id' => $articleCardArticleId > 0 ? $articleCardArticleId : null,
                'is_deleted' => 0,
                'edited_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($shoutId < 1) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Shout gonderilemedi.'];
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::warning('Shout create failed.', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Shout gonderilemedi.'];
        }

        $this->registerSuccessfulPost($uid);
        if ($isReply && $parentShout) {
            $this->notifyReplyTarget($uid, $parentShout, (int) $shoutId);
        }
        $this->notifyMentionedUsers($body, $uid, (int) $shoutId, $isReply && $parentShout ? [(int) ($parentShout->uid ?? 0)] : []);
        if ($isReply && $parentShout) {
            $this->maybeNotifyTrending((int) ($parentShout->id ?? 0), $uid);
        }
        if ($isStateDecree && $decreeCountryId > 0) {
            $this->notifyStateDecreePublished($uid, (int) $shoutId, $decreeCountryId);
        }

        return [
            'error' => false,
            'message' => $isReply ? 'Yanit paylasildi.' : 'Shout paylasildi.',
            'created_shout_id' => (int) $shoutId,
            'created_parent_id' => $isReply ? $parentId : 0,
            'force_mode' => $isReply ? ($_POST['mode'] ?? 'latest') : 'latest',
            'feed' => $this->feedPayload(
                $isReply ? ($_POST['mode'] ?? 'latest') : 'latest',
                $isReply ? (int) ($_POST['offset'] ?? 0) : 0,
                self::DEFAULT_PAGE_SIZE,
                $isReply ? max(1, (int) ($_POST['page'] ?? 1)) : 1
            ),
        ];
    }

    public function votePoll()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);
        $pollOptionId = (int) ($_POST['poll_option_id'] ?? 0);

        if ($uid < 1 || $shoutId < 1 || $pollOptionId < 1) {
            return ['error' => true, 'message' => 'Gecersiz anket secimi.'];
        }

        if (!$this->isFeatureSchemaReady()) {
            return ['error' => true, 'message' => 'Shout anket sistemi henuz hazir degil.'];
        }

        $limit = ActionRateLimiter::throttle(
            'shout.poll.vote',
            'uid:' . $uid,
            20,
            300,
            300,
            'Cok hizli anket oylamasi yapiyorsun. Lutfen biraz bekle.'
        );
        if ($limit) {
            return $limit;
        }

        try {
            DB::beginTransaction();

            $shout = ShoutModel::where('id', $shoutId)->where('is_deleted', 0)->lockForUpdate()->first();
            if (!$shout || (int) ($shout->has_poll ?? 0) !== 1) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Anket bulunamadi.'];
            }

            if (!empty($shout->poll_expires_at) && strtotime((string) $shout->poll_expires_at) <= time()) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Bu anketin suresi dolmus.'];
            }

            $existingVote = ShoutModel::findPollVote($shoutId, $uid);
            if ($existingVote) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Bu ankette zaten oy kullandin.'];
            }

            $pollOptions = ShoutModel::decodePollData($shout->poll_data ?? null);
            $validOptionKeys = array_map(function ($option) {
                return (int) ($option['option_key'] ?? 0);
            }, $pollOptions);

            if (!in_array($pollOptionId, $validOptionKeys, true)) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Secilen anket secenegi bulunamadi.'];
            }

            ShoutModel::createPollVote([
                'shout_id' => $shoutId,
                'poll_option_id' => $pollOptionId,
                'uid' => $uid,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!ShoutModel::incrementPollVote($shoutId, $pollOptionId, date('Y-m-d H:i:s'))) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Anket oyu kaydedilemedi.'];
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::warning('Shout poll vote failed.', [
                'uid' => $uid,
                'shout_id' => $shoutId,
                'poll_option_id' => $pollOptionId,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Anket oyu kaydedilemedi.'];
        }

        return [
            'error' => false,
            'message' => '',
            'shout_id' => $shoutId,
            'poll' => ShoutModel::buildPollPayload($shout),
        ];
    }

    public function tip()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);
        $goldAmount = (int) ($_POST['gold_amount'] ?? 0);

        if ($uid < 1 || $shoutId < 1 || !in_array($goldAmount, self::TIP_ALLOWED_AMOUNTS, true)) {
            return ['error' => true, 'message' => 'Gecersiz destek secildi.'];
        }

        if (!$this->isFeatureSchemaReady()) {
            return ['error' => true, 'message' => 'Destek sistemi henuz hazir degil.'];
        }

        $limit = ActionRateLimiter::throttle(
            'shout.tip',
            'uid:' . $uid,
            12,
            3600,
            1800,
            'Cok fazla destek islemi yaptin. Lutfen biraz bekle.'
        );
        if ($limit) {
            return $limit;
        }

        try {
            DB::beginTransaction();

            $shout = ShoutModel::where('id', $shoutId)->where('is_deleted', 0)->lockForUpdate()->first();
            if (!$shout) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Shout bulunamadi.'];
            }

            $targetUid = (int) ($shout->uid ?? 0);
            if ($targetUid < 1 || $targetUid === $uid) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Kendi shoutuna destek gonderemezsin.'];
            }

            $fromMoney = DB::table('user_money')->where('uid', $uid)->lockForUpdate()->first();
            $toMoney = DB::table('user_money')->where('uid', $targetUid)->lockForUpdate()->first();

            if (!$fromMoney || (float) ($fromMoney->gold ?? 0) < (float) $goldAmount) {
                DB::rollBack();
                return ['error' => true, 'message' => 'Yeterli Gold bakiyen yok.'];
            }

            if (!$toMoney) {
                DB::table('user_money')->insert([
                    'uid' => $targetUid,
                    'gold' => 0,
                ]);
            }

            DB::table('user_money')->where('uid', $uid)->update([
                'gold' => round((float) ($fromMoney->gold ?? 0) - (float) $goldAmount, 2),
            ]);

            $targetCurrentGold = (float) (($toMoney->gold ?? 0));
            DB::table('user_money')->where('uid', $targetUid)->update([
                'gold' => round($targetCurrentGold + (float) $goldAmount, 2),
            ]);

            ShoutModel::createTip([
                'shout_id' => $shoutId,
                'from_uid' => $uid,
                'to_uid' => $targetUid,
                'gold_amount' => $goldAmount,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            DB::table('shouts')->where('id', $shoutId)->update([
                'tips_gold_total' => DB::raw('tips_gold_total + ' . (int) $goldAmount),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            self::adjustMediaReputation($targetUid, max(1, $goldAmount * self::MEDIA_REPUTATION_TIP_MULTIPLIER));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::warning('Shout tip failed.', [
                'uid' => $uid,
                'shout_id' => $shoutId,
                'gold_amount' => $goldAmount,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Destek islemi tamamlanamadi.'];
        }

        Notify::push(
            $targetUid,
            'shout_tip',
            'Bir shoutun destek aldi',
            $goldAmount . ' Gold destek aldigin bir shout var.',
            $this->app->getContainer()->get('router')->pathFor('home') . '#shout-' . $shoutId,
            ['shout_id' => $shoutId, 'from_uid' => $uid, 'gold_amount' => $goldAmount]
        );

        if ((int) ($shout->parent_id ?? 0) < 1) {
            $this->maybeNotifyTrending($shoutId, $uid);
        }

        return [
            'error' => false,
            'message' => '',
            'shout_id' => $shoutId,
            'tips_total_gold' => round((float) DB::table('shouts')->where('id', $shoutId)->value('tips_gold_total'), 2),
            'tips_count' => (int) DB::table('shout_tips')->where('shout_id', $shoutId)->count(),
        ];
    }

    public function toggleLike()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);

        if ($uid < 1 || $shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout secildi.'];
        }

        if (!$this->isSchemaReady()) {
            return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
        }

        $limit = ActionRateLimiter::throttle(
            'shout.like',
            'uid:' . $uid,
            20,
            60,
            60,
            'Begeni limiti doldu. Lutfen biraz bekle.'
        );
        if ($limit) {
            return $limit;
        }

        try {
            $shout = ShoutModel::where('id', $shoutId)->where('is_deleted', 0)->first();
            if (!$shout) {
                return ['error' => true, 'message' => 'Shout bulunamadi.'];
            }

            DB::beginTransaction();

            $existing = ShoutLike::where('shout_id', $shoutId)->where('uid', $uid)->first();
            if ($existing) {
                $existing->delete();
                DB::table('shouts')->where('id', $shoutId)->update([
                    'likes_count' => DB::raw('GREATEST(likes_count - 1, 0)'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                self::adjustMediaReputation((int) $shout->uid, -self::MEDIA_REPUTATION_LIKE);
                $liked = false;
            } else {
                if ((int) $shout->uid !== $uid && $this->hasTargetLikeSpam($uid, (int) $shout->uid)) {
                    DB::rollBack();
                    return ['error' => true, 'message' => 'Ayni kullaniciya karsi fazla shout begenisi yaptin.'];
                }

                ShoutLike::create([
                    'shout_id' => $shoutId,
                    'uid' => $uid,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                DB::table('shouts')->where('id', $shoutId)->update([
                    'likes_count' => DB::raw('likes_count + 1'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                self::adjustMediaReputation((int) $shout->uid, self::MEDIA_REPUTATION_LIKE);
                $liked = true;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::warning('Shout like toggle failed.', [
                'uid' => $uid,
                'shout_id' => $shoutId,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Begeni islemi tamamlanamadi.'];
        }

        $likesCount = (int) DB::table('shouts')->where('id', $shoutId)->value('likes_count');

        if ($liked && (int) $shout->uid !== $uid) {
            Notify::push(
                (int) $shout->uid,
                'shout_like',
                'Bir shoutun begenildi',
                'Bir kullanici shoutunu begendi.',
                $this->app->getContainer()->get('router')->pathFor('home') . '#shout-' . $shoutId,
                ['shout_id' => $shoutId, 'from_uid' => $uid]
            );
        }

        if ($liked && (int) ($shout->parent_id ?? 0) < 1) {
            $this->maybeNotifyTrending($shoutId, $uid);
        }

        return [
            'error' => false,
            'message' => $liked ? 'Shout begenildi.' : 'Begeni kaldirildi.',
            'liked' => $liked,
            'likes_count' => $likesCount,
        ];
    }

    public function report()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);
        $reason = $this->normalizeBody($_POST['reason'] ?? '');

        if ($uid < 1 || $shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout secildi.'];
        }

        if (!$this->isSchemaReady()) {
            return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
        }

        if ($reason === '' || mb_strlen($reason) < 5) {
            return ['error' => true, 'message' => 'Rapor sebebini en az 5 karakter olarak yazmalisin.'];
        }

        $limit = ActionRateLimiter::throttle(
            'shout.report',
            'uid:' . $uid,
            8,
            3600,
            3600,
            'Cok fazla rapor gonderdin. Lutfen daha sonra tekrar dene.'
        );
        if ($limit) {
            return $limit;
        }

        $reporterCooldown = $this->getReporterCooldownUntil($uid);
        if ($reporterCooldown) {
            $remainingSeconds = max(1, $reporterCooldown - time());
            return [
                'error' => true,
                'message' => 'Cok fazla shout raporu gonderdigin icin gecici olarak kisitlandin.',
                'retry_after' => $remainingSeconds,
            ];
        }

        try {
            $shout = ShoutModel::where('id', $shoutId)->where('is_deleted', 0)->first();
            if (!$shout) {
                return ['error' => true, 'message' => 'Shout bulunamadi.'];
            }

            if ((int) $shout->uid === $uid) {
                return ['error' => true, 'message' => 'Kendi shoutunu raporlayamazsin.'];
            }

            if ($this->hasTargetReportSpam($uid, (int) $shout->uid)) {
                return ['error' => true, 'message' => 'Ayni kullaniciya karsi fazla rapor gonderdin.'];
            }

            $existing = ShoutReport::where('shout_id', $shoutId)->where('uid', $uid)->first();
            if ($existing) {
                return ['error' => true, 'message' => 'Bu shout zaten raporlandi.'];
            }

            ShoutReport::create([
                'shout_id' => $shoutId,
                'uid' => $uid,
                'reason' => $reason,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            DB::table('shouts')->where('id', $shoutId)->update([
                'reports_count' => DB::raw('reports_count + 1'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            self::adjustMediaReputation((int) $shout->uid, self::MEDIA_REPUTATION_REPORT);
            $this->applyAutomaticRestriction((int) $shout->uid, 'report');
            $reportCount = (int) DB::table('shouts')->where('id', $shoutId)->value('reports_count');
            $hiddenForReview = $this->maybeAutoHideReportedShout($shoutId, $reportCount);
        } catch (\Exception $e) {
            Logger::warning('Shout report failed.', [
                'uid' => $uid,
                'shout_id' => $shoutId,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Rapor gonderilemedi.'];
        }

        $this->notifyReportedTarget($uid, $shout, $reportCount ?? 1);
        if ((int) ($shout->parent_id ?? 0) < 1) {
            $this->maybeNotifyCountryCriticalShout($shoutId, $uid);
        }

        return [
            'error' => false,
            'message' => !empty($hiddenForReview) ? 'Shout raporlandi ve incelemeye alindi.' : 'Shout raporlandi.',
            'hidden_for_review' => !empty($hiddenForReview),
            'report_count' => (int) ($reportCount ?? 1),
        ];
    }

    public function edit()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);
        $body = $this->normalizeBody($_POST['body'] ?? '');
        $articleCardArticleId = $this->detectArticleCardArticleId($body);
        $linkCheckBody = $articleCardArticleId > 0 ? self::stripArticleLinksFromBody($body) : $body;
        $length = function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body);

        if ($uid < 1 || $shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout secildi.'];
        }

        if (!$this->isSchemaReady()) {
            return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
        }

        if ($length < self::MIN_BODY_LENGTH || $length > self::MAX_BODY_LENGTH) {
            return ['error' => true, 'message' => 'Shout 3 ile 240 karakter arasinda olmali.'];
        }

        if ($this->containsBlockedLink($linkCheckBody) || $this->containsBlockedTerm($body)) {
            return ['error' => true, 'message' => 'Bu shout icerigi guncellenemedi.'];
        }

        if ($this->isLowQualityBody($body)) {
            return ['error' => true, 'message' => 'Shout daha acik ve anlamli olmali.'];
        }

        $shout = ShoutModel::where('id', $shoutId)->where('is_deleted', 0)->first();
        if (!$shout || (int) $shout->uid !== $uid) {
            return ['error' => true, 'message' => 'Bu shoutu duzenleme yetkin yok.'];
        }

        $createdAt = !empty($shout->created_at) ? strtotime((string) $shout->created_at) : 0;
        if (!$createdAt || $createdAt < strtotime('-' . self::EDIT_WINDOW_MINUTES . ' minutes')) {
            return ['error' => true, 'message' => 'Shout duzenleme suresi doldu.'];
        }

        if ($this->isFeatureSchemaReady()) {
            $cooldownUntil = $this->getPostingCooldownUntil($uid);
            if ($cooldownUntil) {
                $remainingSeconds = max(1, $cooldownUntil - time());
                $restrictionState = self::getRestrictionState($uid);

                return [
                    'error' => true,
                    'message' => !empty($restrictionState['reason']) ? (string) $restrictionState['reason'] : 'Shout duzenleme gecici olarak kisitlandi.',
                    'retry_after' => $remainingSeconds,
                    'restriction' => $restrictionState + [
                        'is_muted' => true,
                        'remaining_seconds' => $remainingSeconds,
                        'remaining_label' => self::humanRemaining($remainingSeconds),
                    ],
                ];
            }
        }

        if ($this->looksLikeCopyPasteSpam($uid, $body, $shoutId)) {
            return ['error' => true, 'message' => 'Benzer shoutlari tekrar paylasamazsin.'];
        }

        try {
            DB::table('shouts')
                ->where('id', $shoutId)
                ->update([
                    'body' => $body,
                    'article_card_article_id' => $articleCardArticleId > 0 ? $articleCardArticleId : null,
                    'edited_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Exception $e) {
            return ['error' => true, 'message' => 'Shout duzenlenemedi.'];
        }

        $this->notifyMentionedUsers($body, $uid, $shoutId);

        return [
            'error' => false,
            'message' => 'Shout guncellendi.',
            'feed' => $this->feedPayload(
                $_POST['mode'] ?? 'latest',
                (int) ($_POST['offset'] ?? 0),
                self::DEFAULT_PAGE_SIZE,
                max(1, (int) ($_POST['page'] ?? 1))
            ),
        ];
    }

    public function delete()
    {
        $csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->validateCsrf($csrfToken)) {
            return ['error' => true, 'message' => 'Guvenlik dogrulamasi gecersiz.'];
        }

        $uid = $this->currentUserId();
        $shoutId = (int) ($_POST['shout_id'] ?? 0);

        if ($uid < 1 || $shoutId < 1) {
            return ['error' => true, 'message' => 'Gecersiz shout secildi.'];
        }

        if (!$this->isSchemaReady()) {
            return ['error' => true, 'message' => 'Shout sistemi henuz hazir degil.'];
        }

        $shout = ShoutModel::find($shoutId);
        if (!$shout || (int) $shout->is_deleted === 1) {
            return ['error' => true, 'message' => 'Shout bulunamadi.'];
        }

        $isAdmin = $this->currentUserIsAdmin();
        if ((int) $shout->uid !== $uid && !$isAdmin) {
            return ['error' => true, 'message' => 'Bu shoutu silme yetkin yok.'];
        }

        try {
            DB::beginTransaction();

            DB::table('shouts')->where('id', $shoutId)->update([
                'is_deleted' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($isAdmin && (int) $shout->uid !== $uid) {
                self::adjustMediaReputation((int) $shout->uid, self::MEDIA_REPUTATION_ADMIN_DELETE);
                Notify::push(
                    (int) $shout->uid,
                    'shout_deleted',
                    'Bir shoutun kaldirildi',
                    'Admin bir shoutunu kaldirdi.',
                    $this->app->getContainer()->get('router')->pathFor('home'),
                    ['shout_id' => $shoutId]
                );

                $this->applyAutomaticRestriction((int) $shout->uid, 'delete');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::warning('Shout delete failed.', [
                'uid' => $uid,
                'shout_id' => $shoutId,
                'message' => $e->getMessage(),
            ]);

            return ['error' => true, 'message' => 'Shout silinemedi.'];
        }

        $mode = $_POST['mode'] ?? 'latest';
        $offset = (int) ($_POST['offset'] ?? 0);

        return [
            'error' => false,
            'message' => 'Shout kaldirildi.',
            'feed' => $this->feedPayload($mode, $offset, self::DEFAULT_PAGE_SIZE, max(1, (int) ($_POST['page'] ?? 1))),
        ];
    }
}

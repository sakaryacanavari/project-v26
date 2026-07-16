<?php

namespace App\Controllers;

use App\Models\CompanyType;
use App\Models\CongressMember;
use App\Models\Country;
use App\Models\UserGym;
use App\Models\UserItem;
use App\Models\UserMoney;
use App\System\App;
use App\System\AppException;
use App\System\Controller;
use App\System\MarketOrderService;
use \App\Models\User as UserModel;
use Illuminate\Database\Capsule\Manager as DB;

class User extends Controller
{
    private const RESERVED_NICK_EXACT = [
        'admin',
        'administrator',
        'root',
        'system',
        'support',
        'moderator',
        'mod',
        'official',
        'owner',
        'staff',
        'developer',
        'dev',
        'omni',
        'srepublik',
        'srepublik',
    ];

    private const RESERVED_NICK_PARTIAL = [
        'admin',
        'root',
        'mod',
        'gm',
        'support',
        'staff',
        'official',
        'system',
    ];

    private const BLOCKED_NICK_WORDS = [
        'hitler',
        'nazi',
    ];

    private const COMMON_PASSWORDS = [
        '123456',
        '12345678',
        '123456789',
        '1234567890',
        'qwerty',
        'qwerty123',
        'password',
        'password123',
        'admin123',
        'welcome',
        'letmein',
        'iloveyou',
    ];

    private const DISPOSABLE_EMAIL_DOMAINS = [
        '10minutemail.com',
        '10minutemail.net',
        'guerrillamail.com',
        'mailinator.com',
        'temp-mail.org',
        'tempmail.com',
        'yopmail.com',
        'sharklasers.com',
        'dispostable.com',
        'trashmail.com',
        'maildrop.cc',
        'moakt.com',
        'fakeinbox.com',
        'getnada.com',
        'mintemail.com',
    ];

    private const RATE_LIMITS = [
        'nick_check_ip' => ['limit' => 40, 'window' => 300, 'block' => 600],
        'nick_check_fingerprint' => ['limit' => 40, 'window' => 300, 'block' => 600],
        'signup_ip' => ['limit' => 8, 'window' => 1800, 'block' => 3600],
        'signup_email' => ['limit' => 3, 'window' => 1800, 'block' => 3600],
        'signup_fingerprint' => ['limit' => 8, 'window' => 1800, 'block' => 3600],
        'signup_actor' => ['limit' => 6, 'window' => 1800, 'block' => 3600],
        'signup_email_actor' => ['limit' => 2, 'window' => 1800, 'block' => 3600],
        'login_ip' => ['limit' => 18, 'window' => 900, 'block' => 1800],
        'login_email' => ['limit' => 8, 'window' => 900, 'block' => 1800],
        'login_fingerprint' => ['limit' => 14, 'window' => 900, 'block' => 1800],
        'login_actor' => ['limit' => 10, 'window' => 900, 'block' => 1800],
        'login_email_actor' => ['limit' => 5, 'window' => 900, 'block' => 1800],
        'login_suspicious_actor' => ['limit' => 7, 'window' => 600, 'block' => 1800],
        'verify_resend_ip' => ['limit' => 5, 'window' => 1800, 'block' => 3600],
        'verify_resend_email' => ['limit' => 3, 'window' => 1800, 'block' => 3600],
        'password_reset_ip' => ['limit' => 5, 'window' => 1800, 'block' => 3600],
        'password_reset_email' => ['limit' => 3, 'window' => 1800, 'block' => 3600],
        'password_reset_fingerprint' => ['limit' => 5, 'window' => 1800, 'block' => 3600],
    ];

    private const EMAIL_VERIFICATION_EXPIRES_HOURS = 24;
    private const PASSWORD_RESET_EXPIRES_HOURS = 2;

    public function showGyms()
    {
        $uid = App::user()->getUid();

        $gymsModel = UserGym::where([
            "uid" => $uid
        ])->first();

        if (empty($gymsModel)) {
            $gymsModel = UserGym::create([
                "uid" => $uid
            ]);
        }

        $hasDailyTrainingToday = UserGym::hasTrainingQualityToday($uid, 1, $gymsModel);
        $dailyGain = (int) (UserGym::$data['q1']['strength'] ?? 5);
        $trainingHistory = [];
        $trainingHistoryKeys = [];

        $schema = DB::getSchemaBuilder();
        if ($schema->hasTable('user_trainings')
            && $schema->hasColumn('user_trainings', 'created_at')
            && $schema->hasColumn('user_trainings', 'strength_gained')) {
            $trainingColumns = ['created_at', 'strength_gained'];
            if ($schema->hasColumn('user_trainings', 'quality')) {
                $trainingColumns[] = 'quality';
            }
            $rows = DB::table('user_trainings')
                ->where('uid', (int) $uid)
                ->orderBy('created_at', 'desc')
                ->limit(7)
                ->get($trainingColumns);

            foreach ($rows as $row) {
                $timestamp = strtotime((string) ($row->created_at ?? ''));
                $gain = (int) ($row->strength_gained ?? 0);
                if ($timestamp === false || $gain <= 0) {
                    continue;
                }

                $trainingHistoryKeys[date('Y-m-d H:i:s', $timestamp) . '|' . $gain] = true;
                $trainingHistory[] = [
                    'timestamp' => $timestamp,
                    'dateLabel' => date('d.m.Y H:i', $timestamp),
                    'strengthGain' => $gain,
                    'strengthAfter' => null,
                    'trainingType' => ((int) ($row->quality ?? 1) === 2) ? 'Ek Eğitim' : 'Günlük Eğitim',
                ];
            }
        }

        $dailyActionsReady = UserGym::ensureDailyActionsTable();
        if ($dailyActionsReady) {
            $actionColumns = ['action', 'created_at', 'reward_amount'];
            if (UserGym::hasDailyActionsColumn('strength_after')) {
                $actionColumns[] = 'strength_after';
            }
            $trainingRows = DB::table('user_gym_daily_actions')
                ->where('uid', (int) $uid)
                ->whereIn('action', ['free_training', 'extra_training'])
                ->where('reward_type', 'strength')
                ->where('reward_amount', '>', 0)
                ->orderBy('created_at', 'desc')
                ->limit(20)
                ->get($actionColumns);

            foreach ($trainingRows as $row) {
                $timestamp = strtotime((string) ($row->created_at ?? ''));
                $gain = (int) ($row->reward_amount ?? 0);
                if ($timestamp === false || $gain <= 0) {
                    continue;
                }

                $key = date('Y-m-d H:i:s', $timestamp) . '|' . $gain;
                if (isset($trainingHistoryKeys[$key])) {
                    $trainingHistory = array_values(array_filter($trainingHistory, function (array $item) use ($timestamp, $gain) {
                        return !(($item['timestamp'] ?? 0) === $timestamp && ($item['strengthGain'] ?? 0) === $gain && ($item['strengthAfter'] ?? null) === null);
                    }));
                }
                $trainingHistoryKeys[$key] = true;
                $trainingHistory[] = [
                    'timestamp' => $timestamp,
                    'dateLabel' => date('d.m.Y H:i', $timestamp),
                    'strengthGain' => $gain,
                    'strengthAfter' => isset($row->strength_after) ? (int) $row->strength_after : null,
                    'trainingType' => ((string) ($row->action ?? '') === 'extra_training') ? 'Ek Eğitim' : 'Günlük Eğitim',
                ];
            }
        }

        $trainingStreak = UserGym::getDailyTrainingStreak($uid, $gymsModel);
        $weekStart = strtotime('monday this week 00:00:00');
        $weeklyTrainingGain = 0;
        $weeklyTrainingCount = 0;
        foreach ($trainingHistory as $training) {
            $timestamp = (int) ($training['timestamp'] ?? 0);
            if ($timestamp >= $weekStart && $timestamp <= time()) {
                $weeklyTrainingGain += (int) ($training['strengthGain'] ?? 0);
                $weeklyTrainingCount++;
            }
        }

        usort($trainingHistory, function (array $left, array $right) {
            return ($right['timestamp'] ?? 0) <=> ($left['timestamp'] ?? 0);
        });
        $trainingHistory = array_slice($trainingHistory, 0, 3);

        $userRow = DB::table('users')->where('id', (int) $uid)->first(['strength']);
        $sessionUser = App::user()->getUser();
        $currentStrength = (int) ($userRow->strength ?? ($sessionUser['strength'] ?? 0));
        $goldBalance = 0;
        $walletReady = $schema->hasTable('user_money') && $schema->hasColumn('user_money', 'gold');
        if ($walletReady) {
            $wallet = DB::table('user_money')->where('uid', (int) $uid)->first(['gold']);
            $walletReady = !empty($wallet);
            $goldBalance = (float) ($wallet->gold ?? 0);
        }

        $extraUsed = UserGym::hasTrainingQualityToday($uid, 2, $gymsModel)
            || ($dailyActionsReady && UserGym::hasDailyActionToday($uid, 'extra_training'));
        $wheelAction = $dailyActionsReady ? UserGym::getDailyActionToday($uid, 'reward_wheel') : null;
        $wheelReward = null;
        if ($wheelAction) {
            $rewardType = (string) ($wheelAction->reward_type ?? '');
            $rewardAmount = (int) ($wheelAction->reward_amount ?? 0);
            $rewardLabels = [
                'gold' => 'Gold',
                'esp' => 'ESP',
                'strength' => 'Guc',
            ];
            if (isset($rewardLabels[$rewardType]) && $rewardAmount > 0) {
                $wheelReward = '+' . $rewardAmount . ' ' . $rewardLabels[$rewardType];
            }
        }

        return $this->render('user/gyms.html.twig', [
            "dailyTraining" => [
                "currentStrength" => $currentStrength,
                "strengthGain" => $dailyGain,
                "completed" => $hasDailyTrainingToday,
                "trainingStreak" => $trainingStreak,
                "resetAt" => date('c', strtotime('tomorrow')),
                "serverNow" => date('c'),
                "lastTraining" => $trainingHistory[0] ?? null,
                "history" => $trainingHistory,
                "weekly" => [
                    "gain" => $weeklyTrainingGain,
                    "count" => $weeklyTrainingCount,
                ],
                "goldBalance" => $goldBalance,
                "walletReady" => $walletReady,
                "extra" => [
                    "cost" => 5,
                    "strengthGain" => 2,
                    "completed" => $extraUsed,
                    "available" => $dailyActionsReady && $walletReady,
                    "canPurchase" => $dailyActionsReady && $walletReady && !$extraUsed && $goldBalance >= 5,
                    "remainingBalance" => max(0, $goldBalance - 5),
                    "missingGold" => max(0, 5 - $goldBalance),
                ],
                "wheel" => [
                    "completed" => (bool) $wheelAction,
                    "available" => $dailyActionsReady,
                    "canSpin" => $dailyActionsReady && !$wheelAction,
                    "reward" => $wheelReward,
                ],
            ],
        ]);
    }

    public function train()
    {
        $uid = App::user()->getUid();
        $quality = isset($_POST["quality"]) ? (int) $_POST["quality"] : 0;

        if ($quality < 1 || $quality > 4) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $gyms = UserGym::where([
            "uid" => $uid
        ])->first();

        if (empty($gyms)) {
            $gyms = UserGym::create([
                "uid" => $uid
            ]);
        }

        if ($gyms->hasAnyTrainingToday()) {
            return $this->validationError("quality", "Bugun sadece 1 egitim yapabilirsiniz.", "daily_limit");
        }

        if ($gyms->hasTrainedToday($quality)) {
            return $this->validationError("quality", "Bu tesis icin bugunku egitim zaten tamamlandi.", "already_trained");
        }

        $gymDetails = UserGym::$data["q$quality"];

        if (!App::user()->buy($gymDetails["cost"], "gold", "TRAIN_Q$quality")) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $gyms["q$quality"] = date("Y-m-d");
        $gyms->save();

        $user = UserModel::where([
            "id" => $uid
        ])->first();

        if (empty($user)) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $user->strength += $gymDetails["strength"];
        return $user->save();
    }

    public function showStorage()
    {
        $uid = (int) App::user()->getUid();
        $countryId = (int) (App::user()->getLocation()["country"]["id"] ?? 1);
        $snapshot = MarketOrderService::storageSnapshot($uid, $countryId);

        return $this->render('user/storage.html.twig', [
            "items" => $snapshot['items'],
            "activeOrders" => $snapshot['activeOrders'],
            "salesHistory" => $snapshot['history'],
            "priceComparisons" => $snapshot['priceComparisons'],
            "storageCapacity" => $snapshot['storageCapacity'],
        ]);
    }

    public function showCitizen($uid)
    {
        $uid = (int) $uid;
        if ($uid < 1) {
            $this->response->status(404);
            return $this->render('common/error.html.twig', [
                'page_title' => \t('citizen.profile.not_found_title'),
                'error_code' => 404,
                'error_title' => \t('citizen.profile.not_found_title'),
                'error_message' => \t('citizen.profile.not_found_message'),
            ]);
        }

        $user = DB::table('users as u')
            ->leftJoin('regions as ur', 'ur.id', '=', 'u.region')
            ->where('u.id', $uid)
            ->first([
                'u.id',
                'u.nick',
                'u.avatar',
                'u.level',
                'u.strength',
                'u.economic_skill',
                'u.economic_xp',
                'u.media_reputation',
                DB::raw(self::hasUsersBioColumn() ? 'u.bio as bio' : "'' as bio"),
                DB::raw('COALESCE(u.country_id, ur.country, 0) as country_id'),
                'ur.name as region_name',
            ]);

        if (!$user) {
            $this->response->status(404);
            return $this->render('common/error.html.twig', [
                'page_title' => \t('citizen.profile.not_found_title'),
                'error_code' => 404,
                'error_title' => \t('citizen.profile.not_found_title'),
                'error_message' => \t('citizen.profile.not_found_message'),
            ]);
        }

        $partyMembership = DB::table('party_members as pm')
            ->leftJoin('political_parties as p', 'p.id', '=', 'pm.party')
            ->where('pm.uid', $uid)
            ->orderByDesc('pm.level')
            ->orderBy('pm.id')
            ->first([
                'pm.party as party_id',
                'pm.level as party_level',
                'p.name as party_name',
                'p.logo_url as party_logo_url',
            ]);

        $countryId = (int) ($user->country_id ?? 0);
        $countryName = '';
        if ($countryId > 0) {
            $countryName = (string) (DB::table('countries')->where('id', $countryId)->value('name') ?? '');
        }

        $presidentCountry = DB::table('countries')
            ->where('president', $uid)
            ->first(['id', 'name']);

        $isCongressMember = CongressMember::where('uid', $uid)->exists();
        $hasNewspaper = DB::table('newspapers')->where('uid', $uid)->exists();
        $newspaperName = (string) (DB::table('newspapers')->where('uid', $uid)->value('name') ?? '');

        $shoutCount = (int) DB::table('shouts')
            ->where('uid', $uid)
            ->where('is_deleted', 0)
            ->whereNull('parent_id')
            ->count();

        $replyCount = (int) DB::table('shouts')
            ->where('uid', $uid)
            ->where('is_deleted', 0)
            ->whereNotNull('parent_id')
            ->count();

        $likesReceived = (int) DB::table('shouts')
            ->where('uid', $uid)
            ->where('is_deleted', 0)
            ->sum('likes_count');

        $lawProposalCount = (int) DB::table('law_proposals')->where('uid', $uid)->count();
        $presidentialWins = DB::getSchemaBuilder()->hasTable('presidential_election_histories')
            ? (int) DB::table('presidential_election_histories')->where('winner_uid', $uid)->count()
            : 0;
        $partyLeadershipWins = DB::getSchemaBuilder()->hasTable('party_election_histories')
            ? (int) DB::table('party_election_histories')->where('winner_uid', $uid)->count()
            : 0;

        $totalWarDamage = DB::getSchemaBuilder()->hasTable('war_damages')
            ? (int) DB::table('war_damages')->where('uid', $uid)->sum('damage')
            : 0;
        $activeWarCount = DB::getSchemaBuilder()->hasTable('war_damages')
            ? (int) DB::table('war_damages as wd')
                ->join('wars as w', 'w.id', '=', 'wd.war_id')
                ->where('wd.uid', $uid)
                ->where('w.status', 'active')
                ->distinct()
                ->count('wd.war_id')
            : 0;
        $bestWarDamage = DB::getSchemaBuilder()->hasTable('war_damages')
            ? (int) (DB::table('war_damages')->where('uid', $uid)->max('damage') ?? 0)
            : 0;

        $companyCount = (int) DB::table('companies')->where('uid', $uid)->count();
        $activeJob = DB::table('work_offers')
            ->where('worker', $uid)
            ->orderByDesc('id')
            ->first(['company', 'salary', 'currency', 'last_work']);
        $activeCompanyName = '';
        if (!empty($activeJob->company)) {
            $activeCompany = DB::table('companies')
                ->where('id', (int) $activeJob->company)
                ->first(['id', 'type', 'quality']);

            if (!empty($activeCompany->type) && isset(CompanyType::$types[$activeCompany->type]['name'])) {
                $activeCompanyName = (string) CompanyType::$types[$activeCompany->type]['name'];

                if (!empty($activeCompany->quality)) {
                    $activeCompanyName = 'Q' . (int) $activeCompany->quality . ' ' . $activeCompanyName;
                }
            } elseif (!empty($activeCompany->id)) {
                $activeCompanyName = 'Tesis #' . (int) $activeCompany->id;
            }
        }

        $recentShouts = DB::table('shouts')
            ->where('uid', $uid)
            ->where('is_deleted', 0)
            ->whereNull('parent_id')
            ->orderByDesc('id')
            ->limit(3)
            ->get([
                'id',
                'body',
                'likes_count',
                'created_at',
                DB::raw('NULL as edited_at'),
            ])->map(function ($row) {
                return [
                    'id' => (int) ($row->id ?? 0),
                    'body_html' => \App\Controllers\Shout::buildDisplayBodyHtml((string) ($row->body ?? '')),
                    'likes_count' => (int) ($row->likes_count ?? 0),
                    'created_at_label' => $this->relativeTimeLabel($row->created_at ?? null),
                    'is_edited' => !empty($row->edited_at),
                ];
            })->values()->all();

        $activityFeed = $this->buildCitizenActivityFeed($uid);
        $timelineItems = $this->buildCitizenTimeline($uid, $presidentCountry, $partyMembership, $isCongressMember);
        $recentWarEntries = $this->buildRecentWarEntries($uid);

        $badgeItems = [];
        if ($presidentCountry) {
            $badgeItems[] = [
                'key' => 'president',
                'label' => \t('citizen.profile.badge_president'),
                'icon' => 'fa-crown',
            ];
        }

        if ((int) ($partyMembership->party_level ?? 0) === 3) {
            $badgeItems[] = [
                'key' => 'party_leader',
                'label' => \t('citizen.profile.badge_party_leader'),
                'icon' => 'fa-flag',
            ];
        }

        if ($isCongressMember) {
            $badgeItems[] = [
                'key' => 'congress',
                'label' => \t('citizen.profile.badge_congress'),
                'icon' => 'fa-building-columns',
            ];
        }

        if ($hasNewspaper) {
            $badgeItems[] = [
                'key' => 'journalist',
                'label' => \t('citizen.profile.badge_journalist'),
                'icon' => 'fa-newspaper',
            ];
        }

        if ((int) ($user->media_reputation ?? 0) >= \App\Controllers\Shout::MEDIA_REPUTATION_VERIFIED) {
            $badgeItems[] = [
                'key' => 'verified',
                'label' => \t('citizen.profile.badge_verified'),
                'icon' => 'fa-badge-check',
            ];
        }

        $achievementItems = [];

        if ($presidentCountry) {
            $achievementItems[] = [
                'icon' => 'fa-crown',
                'title' => \t('citizen.profile.achievement_president_title'),
                'text' => \t('citizen.profile.achievement_president_text'),
            ];
        }

        if ((int) ($partyMembership->party_level ?? 0) === 3) {
            $achievementItems[] = [
                'icon' => 'fa-flag',
                'title' => \t('citizen.profile.achievement_party_title'),
                'text' => \t('citizen.profile.achievement_party_text'),
            ];
        }

        if ($presidentialWins > 0) {
            $achievementItems[] = [
                'icon' => 'fa-landmark',
                'title' => \t('citizen.profile.achievement_presidential_wins_title'),
                'text' => str_replace(':count', (string) $presidentialWins, \t('citizen.profile.achievement_presidential_wins_text')),
            ];
        }

        if ($partyLeadershipWins > 0) {
            $achievementItems[] = [
                'icon' => 'fa-users',
                'title' => \t('citizen.profile.achievement_party_wins_title'),
                'text' => str_replace(':count', (string) $partyLeadershipWins, \t('citizen.profile.achievement_party_wins_text')),
            ];
        }

        if ($totalWarDamage > 0) {
            $achievementItems[] = [
                'icon' => 'fa-shield-halved',
                'title' => \t('citizen.profile.achievement_war_title'),
                'text' => str_replace(':amount', number_format($totalWarDamage, 0, '.', ','), \t('citizen.profile.achievement_war_text')),
            ];
        }

        if ($hasNewspaper) {
            $achievementItems[] = [
                'icon' => 'fa-newspaper',
                'title' => \t('citizen.profile.achievement_media_title'),
                'text' => $newspaperName !== '' ? $newspaperName : \t('citizen.profile.achievement_media_fallback'),
            ];
        }

        $achievementItems = array_slice($achievementItems, 0, 4);

        return $this->render('user/profile.html.twig', [
            'citizen' => [
                'id' => (int) ($user->id ?? 0),
                'nick' => (string) ($user->nick ?? \t('base.sidebar.citizen')),
                'avatar' => (string) ($user->avatar ?? ''),
                'level' => (int) ($user->level ?? 1),
                'strength' => (float) ($user->strength ?? 0),
                'economic_skill' => (int) ($user->economic_skill ?? 1),
                'economic_xp' => (int) ($user->economic_xp ?? 0),
                'media_reputation' => (int) ($user->media_reputation ?? 0),
                'bio' => trim((string) ($user->bio ?? '')),
                'country_id' => $countryId,
                'country_name' => $countryName,
                'region_name' => (string) ($user->region_name ?? ''),
                'party_id' => (int) ($partyMembership->party_id ?? 0),
                'party_name' => (string) ($partyMembership->party_name ?? ''),
                'party_logo_url' => (string) ($partyMembership->party_logo_url ?? ''),
                'badges' => $badgeItems,
                'cover' => [
                    'eyebrow' => $countryName !== '' ? $countryName : \t('citizen.profile.country'),
                    'subtitle' => $partyMembership && !empty($partyMembership->party_name)
                        ? (string) $partyMembership->party_name
                        : \t('citizen.profile.independent'),
                    'supporting' => [
                        \t('citizen.profile.cover_level') . ' ' . (int) ($user->level ?? 1),
                        \t('citizen.profile.cover_strength') . ' ' . number_format((float) ($user->strength ?? 0), 2, '.', ','),
                        \t('citizen.profile.cover_reputation') . ' ' . (int) ($user->media_reputation ?? 0),
                    ],
                ],
                'stats' => [
                    'shouts' => $shoutCount,
                    'replies' => $replyCount,
                    'likes_received' => $likesReceived,
                ],
                'politics' => [
                    'is_president' => !empty($presidentCountry),
                    'is_party_leader' => ((int) ($partyMembership->party_level ?? 0) === 3),
                    'is_congress_member' => $isCongressMember,
                    'law_proposals' => $lawProposalCount,
                    'presidential_wins' => $presidentialWins,
                    'party_leadership_wins' => $partyLeadershipWins,
                ],
                'war' => [
                    'total_damage' => $totalWarDamage,
                    'active_fronts' => $activeWarCount,
                    'best_war_damage' => $bestWarDamage,
                ],
                'economy' => [
                    'company_count' => $companyCount,
                    'has_newspaper' => $hasNewspaper,
                    'newspaper_name' => $newspaperName,
                    'active_job_name' => $activeCompanyName,
                    'active_job_salary' => (float) ($activeJob->salary ?? 0),
                    'active_job_currency' => strtoupper((string) ($activeJob->currency ?? '')),
                ],
                'achievements' => $achievementItems,
                'timeline' => $timelineItems,
                'recent_wars' => $recentWarEntries,
                'recent_shouts' => $recentShouts,
                'activity_feed' => $activityFeed,
            ],
        ]);
    }

    private static function hasUsersBioColumn()
    {
        static $hasBio = null;

        if ($hasBio !== null) {
            return $hasBio;
        }

        try {
            $hasBio = DB::getSchemaBuilder()->hasColumn('users', 'bio');
        } catch (\Exception $e) {
            $hasBio = false;
        }

        return $hasBio;
    }

    private function buildCitizenActivityFeed($uid)
    {
        $items = [];

        try {
            $shouts = DB::table('shouts')
                ->where('uid', $uid)
                ->where('is_deleted', 0)
                ->orderByDesc('id')
                ->limit(3)
                ->get(['id', 'parent_id', 'body', 'created_at']);

            foreach ($shouts as $row) {
                $items[] = [
                    'type' => empty($row->parent_id) ? 'shout' : 'reply',
                    'label' => empty($row->parent_id) ? \t('citizen.profile.activity_shout') : \t('citizen.profile.activity_reply'),
                    'text' => trim((string) ($row->body ?? '')),
                    'created_at' => $row->created_at ?? null,
                ];
            }
        } catch (\Exception $e) {
        }

        try {
            $articles = DB::table('newspaper_articles')
                ->where('uid', $uid)
                ->orderByDesc('id')
                ->limit(2)
                ->get(['title', 'created_at']);

            foreach ($articles as $row) {
                $items[] = [
                    'type' => 'article',
                    'label' => \t('citizen.profile.activity_article'),
                    'text' => trim((string) ($row->title ?? '')),
                    'created_at' => $row->created_at ?? null,
                ];
            }
        } catch (\Exception $e) {
        }

        try {
            $laws = DB::table('law_proposals')
                ->where('uid', $uid)
                ->orderByDesc('id')
                ->limit(2)
                ->get(['type', 'created_at']);

            foreach ($laws as $row) {
                $items[] = [
                    'type' => 'law',
                    'label' => \t('citizen.profile.activity_law'),
                    'text' => \t('citizen.profile.activity_law_text'),
                    'created_at' => $row->created_at ?? null,
                ];
            }
        } catch (\Exception $e) {
        }

        usort($items, function ($a, $b) {
            return strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));
        });

        $items = array_slice($items, 0, 6);

        foreach ($items as &$item) {
            $item['time_label'] = $this->relativeTimeLabel($item['created_at'] ?? null);
        }

        return $items;
    }

    private function buildCitizenTimeline($uid, $presidentCountry, $partyMembership, $isCongressMember)
    {
        $items = [];

        if ($presidentCountry) {
            $items[] = [
                'label' => \t('citizen.profile.timeline_president'),
                'text' => (string) ($presidentCountry->name ?? \t('citizen.profile.country')),
                'time_label' => \t('citizen.profile.timeline_active_now'),
                'sort_time' => PHP_INT_MAX,
            ];
        }

        if ((int) ($partyMembership->party_level ?? 0) === 3) {
            $items[] = [
                'label' => \t('citizen.profile.timeline_party_leader'),
                'text' => (string) ($partyMembership->party_name ?? \t('citizen.profile.independent')),
                'time_label' => \t('citizen.profile.timeline_active_now'),
                'sort_time' => PHP_INT_MAX - 1,
            ];
        }

        if ($isCongressMember) {
            $items[] = [
                'label' => \t('citizen.profile.timeline_congress'),
                'text' => \t('citizen.profile.timeline_congress_text'),
                'time_label' => \t('citizen.profile.timeline_active_now'),
                'sort_time' => PHP_INT_MAX - 2,
            ];
        }

        try {
            if (DB::getSchemaBuilder()->hasTable('presidential_election_histories')) {
                $wins = DB::table('presidential_election_histories')
                    ->where('winner_uid', $uid)
                    ->orderBy('finished_at', 'desc')
                    ->limit(2)
                    ->get(['country', 'winner_votes', 'finished_at']);

                foreach ($wins as $row) {
                    $countryName = (string) (DB::table('countries')->where('id', (int) ($row->country ?? 0))->value('name') ?? \t('citizen.profile.country'));
                    $finishedAt = (string) ($row->finished_at ?? '');
                    $items[] = [
                        'label' => \t('citizen.profile.timeline_presidential_win'),
                        'text' => $countryName . ' • ' . (int) ($row->winner_votes ?? 0) . ' oy',
                        'time_label' => $this->relativeTimeLabel($finishedAt),
                        'sort_time' => !empty($finishedAt) ? strtotime($finishedAt) : 0,
                    ];
                }
            }
        } catch (\Exception $e) {
        }

        try {
            if (DB::getSchemaBuilder()->hasTable('party_election_histories') && !empty($partyMembership->party_id)) {
                $partyWins = DB::table('party_election_histories')
                    ->where('party_id', (int) $partyMembership->party_id)
                    ->where('winner_uid', $uid)
                    ->orderBy('finished_at', 'desc')
                    ->limit(2)
                    ->get(['winner_votes', 'finished_at']);

                foreach ($partyWins as $row) {
                    $finishedAt = (string) ($row->finished_at ?? '');
                    $items[] = [
                        'label' => \t('citizen.profile.timeline_party_win'),
                        'text' => (string) ($partyMembership->party_name ?? \t('citizen.profile.independent')) . ' • ' . (int) ($row->winner_votes ?? 0) . ' oy',
                        'time_label' => $this->relativeTimeLabel($finishedAt),
                        'sort_time' => !empty($finishedAt) ? strtotime($finishedAt) : 0,
                    ];
                }
            }
        } catch (\Exception $e) {
        }

        usort($items, function ($a, $b) {
            return (int) ($b['sort_time'] ?? 0) <=> (int) ($a['sort_time'] ?? 0);
        });

        return array_slice($items, 0, 6);
    }

    private function buildRecentWarEntries($uid)
    {
        if (!DB::getSchemaBuilder()->hasTable('war_damages')) {
            return [];
        }

        $items = [];

        try {
            $rows = DB::table('war_damages')
                ->where('uid', $uid)
                ->orderBy('created_at', 'desc')
                ->limit(4)
                ->get(['war_id', 'side', 'damage', 'created_at']);

            foreach ($rows as $row) {
                $items[] = [
                    'war_id' => (int) ($row->war_id ?? 0),
                    'side' => (string) ($row->side ?? ''),
                    'damage' => (int) ($row->damage ?? 0),
                    'time_label' => $this->relativeTimeLabel($row->created_at ?? null),
                ];
            }
        } catch (\Exception $e) {
        }

        return $items;
    }

    private function relativeTimeLabel($value)
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$timestamp) {
            return '';
        }

        $diff = max(0, time() - $timestamp);
        if ($diff < 60) {
            return \t('home.shouts.just_now');
        }

        $minutes = (int) floor($diff / 60);
        if ($minutes < 60) {
            return $minutes . ' ' . \t('home.shouts.minutes_short') . ' ' . \t('home.shouts.ago');
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return $hours . ' ' . \t('home.shouts.hours_short') . ' ' . \t('home.shouts.ago');
        }

        $days = (int) floor($hours / 24);
        return $days . ' ' . \t('home.shouts.days_short') . ' ' . \t('home.shouts.ago');
    }

    public function work()
    {
        $jobController = new Job($this->app, $this->response);
        return $jobController->work();
    }

    private function workAtActiveJob()
    {
        App::session()->ensureLogged();

        $uid = (int) App::user()->getUid();

        $jobRow = DB::table('work_offers')
            ->where('worker', $uid)
            ->orderBy('id', 'desc')
            ->first();

        if (empty($jobRow)) {
            return [
                "error" => 1,
                "message" => "Çalışabileceğiniz aktif bir iş bulunamadı."
            ];
        }

        if (!empty($jobRow->last_work) && date('Y-m-d', strtotime($jobRow->last_work)) === date('Y-m-d')) {
            return [
                "error" => 1,
                "message" => "Bugünkü vardiyanızı zaten tamamladınız."
            ];
        }

        $salary = (float) ($jobRow->salary ?? 0);
        $currencyCode = strtolower(trim((string) ($jobRow->currency ?? '')));
        $companyId = (int) ($jobRow->company ?? 0);

        if ($salary <= 0) {
            return [
                "error" => 1,
                "message" => "Bu iş için geçerli maaş tanımlı değil."
            ];
        }

        if ($currencyCode === '') {
            return [
                "error" => 1,
                "message" => "Bu iş için geçerli para birimi tanımlı değil."
            ];
        }

        if ($companyId < 1) {
            return [
                "error" => 1,
                "message" => "Bu işe bağlı geçerli bir şirket bulunamadı."
            ];
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasColumn('user_money', $currencyCode)) {
            return [
                "error" => 1,
                "message" => "Bu para birimi için kullanıcı bakiye kolonu bulunamadı: " . strtoupper($currencyCode)
            ];
        }

        $moneyRow = DB::table('user_money')->where('uid', $uid)->first();
        if (empty($moneyRow)) {
            UserMoney::create([
                "uid" => $uid
            ]);
        }

        $company = DB::table('companies')
            ->where('id', $companyId)
            ->first();

        if (empty($company)) {
            return [
                "error" => 1,
                "message" => "İşe bağlı şirket kaydı bulunamadı."
            ];
        }

        $companyTypeKey = $company->type ?? null;
        $companyQuality = (int) ($company->quality ?? 0);
        $companyOwnerUid = (int) ($company->uid ?? 0);

        if (empty($companyTypeKey) || $companyQuality < 1) {
            return [
                "error" => 1,
                "message" => "Şirket üretim verileri geçersiz."
            ];
        }

        if (!isset(CompanyType::$types[$companyTypeKey])) {
            return [
                "error" => 1,
                "message" => "Şirket tipi sistemde bulunamadı."
            ];
        }

        $companyType = CompanyType::$types[$companyTypeKey];

        if (!isset($companyType['qualities']) || !isset($companyType['qualities'][$companyQuality])) {
            return [
                "error" => 1,
                "message" => "Şirket kalite verisi sistemde bulunamadı."
            ];
        }

        $qualityData = $companyType['qualities'][$companyQuality];
        $productId = isset($companyType['product']) ? $companyType['product'] : null;
        $produceAmount = (int) ($qualityData['product_amount'] ?? 0);
        $consumeProduct = (int) ($qualityData['consume_product'] ?? 0);
        $consumeAmount = (int) ($qualityData['consume_amount'] ?? 0);

        if (empty($productId) || $produceAmount <= 0) {
            return [
                "error" => 1,
                "message" => "Bu şirket için geçerli üretim çıktısı tanımlı değil."
            ];
        }

        if ($companyOwnerUid < 1) {
            return [
                "error" => 1,
                "message" => "Şirket sahibine ait geçerli kullanıcı bulunamadı."
            ];
        }

        DB::beginTransaction();

        try {
            if ($consumeProduct > 0 && $consumeAmount > 0) {
                $ownerInputItem = DB::table('user_items')
                    ->where('uid', $companyOwnerUid)
                    ->where('item', $consumeProduct)
                    ->where('quality', $companyQuality)
                    ->lockForUpdate()
                    ->first();

                $ownerInputQty = (int) ($ownerInputItem->quantity ?? 0);

                if ($ownerInputQty < $consumeAmount) {
                    DB::rollBack();

                    return [
                        "error" => 1,
                        "message" => "Şirket üretim için yeterli girdi stoğuna sahip değil."
                    ];
                }

                $newInputQty = $ownerInputQty - $consumeAmount;

                if ($newInputQty > 0) {
                    DB::table('user_items')
                        ->where('id', (int) $ownerInputItem->id)
                        ->update([
                            'quantity' => $newInputQty
                        ]);
                } else {
                    DB::table('user_items')
                        ->where('id', (int) $ownerInputItem->id)
                        ->delete();
                }
            }

            $ownerOutputItem = DB::table('user_items')
                ->where('uid', $companyOwnerUid)
                ->where('item', $productId)
                ->where('quality', $companyQuality)
                ->lockForUpdate()
                ->first();

            if (!empty($ownerOutputItem)) {
                DB::table('user_items')
                    ->where('id', (int) $ownerOutputItem->id)
                    ->update([
                        'quantity' => (int) $ownerOutputItem->quantity + $produceAmount
                    ]);
            } else {
                DB::table('user_items')->insert([
                    'uid' => $companyOwnerUid,
                    'item' => $productId,
                    'quality' => $companyQuality,
                    'quantity' => $produceAmount
                ]);
            }

            DB::table('user_money')
                ->where('uid', $uid)
                ->increment($currencyCode, $salary);

            DB::table('work_offers')
                ->where('id', (int) $jobRow->id)
                ->update([
                    'last_work' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                "error" => 1,
                "message" => "Vardiya tamamlanamadı: " . $e->getMessage()
            ];
        }

        $this->grantEconomicWorkProgress($uid, 1);

        return [
            "error" => 0,
            "message" => "Vardiya tamamlandı. " . number_format($salary, 2) . " " . strtoupper($currencyCode) . " kazandınız."
        ];
    }

    public function showSignup()
    {
        $this->redirectLoggedUsers();

        $referrer = isset($_GET['referrer']) ? (int) $_GET['referrer'] : 0;

        return $this->render('user/signup.html.twig', [
            "referrer" => $referrer,
            "countries" => Country::get()
        ]);
    }

    public function signup()
    {
        $email = self::normalizeEmail($_POST["email"] ?? "");
        $nick = self::normalizeNick($_POST["username"] ?? "");
        $password = (string) ($_POST["password"] ?? "");
        $passwordRepeat = (string) ($_POST["password2"] ?? "");
        $referrer = (int) ($_POST["referrer"] ?? 0);
        $countryId = (int) ($_POST["country"] ?? 0);
        $humanVerified = (string) ($_POST["human_verified"] ?? "0");
        $termsAccepted = !empty($_POST["terms"]);
        $clientIp = $this->getClientIp();
        $fingerprint = $this->getRequestFingerprint();
        $signupScopes = $this->buildSignupRateLimitScopes($clientIp, $email, $fingerprint);

        $blocked = $this->assertRateLimitScopes($signupScopes, "general");
        if ($blocked !== null) {
            return $blocked;
        }

        if ($email === '' || $nick === '' || $password === '' || $countryId < 1) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("general", "Zorunlu alanlari eksiksiz doldurun.");
        }
        if ($humanVerified !== "1") {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("general", "Guvenlik dogrulamasini tamamlayin.");
        }
        if (!$termsAccepted) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("general", "Hizmet sartlarini kabul etmeniz gerekiyor.");
        }

        $nickError = self::validateNickname($nick);
        if ($nickError !== null) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("username", $nickError);
        }
        if (!self::isValidEmail($email)) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("email", "Gecerli bir e-posta adresi girin.");
        }
        if (strlen($email) > 150) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("email", "E-posta adresi cok uzun.");
        }
        if ($this->isDisposableEmailDomain($email)) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("email", "Gecici e-posta servisleri ile kayit yapilamaz.");
        }

        $passwordError = self::validatePassword($password, $passwordRepeat, $nick, $email);
        if ($passwordError !== null) {
            $this->hitRateLimitScopes($signupScopes);
            $field = $password === $passwordRepeat ? "password" : "password2";
            return $this->validationError($field, $passwordError);
        }

        $country = Country::find($countryId);
        if (empty($country)) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("country", "Gecerli bir ulke secin.");
        }
        $regionId = $this->resolveSignupRegionId($country);
        if ($regionId < 1) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("country", "Secilen ulke icin gecerli baslangic bolgesi bulunamadi.");
        }
        if ($referrer > 0 && $referrer === (int) App::user()->getUid()) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("referrer", "Davet kodu gecersiz.");
        }
        if ($referrer > 0 && !UserModel::where("id", $referrer)->exists()) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("referrer", "Davet kodu sistemde bulunamadi.");
        }
        if (UserModel::where("email", $email)->exists()) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("email", "Bu e-posta adresi zaten kullanimda.");
        }
        if (DB::table('users')->whereRaw('LOWER(nick) = ?', [strtolower($nick)])->exists()) {
            $this->hitRateLimitScopes($signupScopes);
            return $this->validationError("username", "Bu kullanici adi zaten alinmis.");
        }

        $this->hitRateLimitScopes($signupScopes);

        $data = [
            "email" => $email,
            "nick" => $nick,
            "password" => self::hashPassword($password),
            "status" => UserModel::STATUS_ACTIVATED,
            "region" => $regionId,
            "country_id" => (int) $country->id,
            "theme" => "dark_cyan",
            "language" => "tr",
            "economic_skill" => 1,
            "economic_xp" => 0,
        ];
        if ($referrer > 0) {
            $data["referrer"] = $referrer;
        }

        DB::beginTransaction();
        try {
            $user = UserModel::create($data);
            UserMoney::create(["uid" => $user->id]);
            UserGym::create(["uid" => $user->id]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new \Exception("Kayit islemi tamamlanamadi.", AppException::ACTION_FAILED);
        }

        $res = new \stdClass();
        $res->error = 0;
        $res->id = (int) $user->id;
        $res->message = "Kayit tamamlandi.";
        $res->field = null;
        return $res;
    }

    private static function hashPassword($password)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }

        return password_hash($password, PASSWORD_BCRYPT);
    }

    private static function encryptPassword($password)
    {
        return md5(App::container()->get("settings")["password_hash"] . $password);
    }

    public function showLogin()
    {
        $this->redirectLoggedUsers();

        $notice = null;
        if (!empty($_GET['verified'])) {
            $notice = [
                'type' => 'success',
                'title' => \t('auth.login.notice_verified_title'),
                'message' => \t('auth.login.notice_verified_text'),
            ];
        } elseif (!empty($_GET['verification_error'])) {
            $notice = [
                'type' => 'warning',
                'title' => \t('auth.login.notice_verification_error_title'),
                'message' => \t('auth.login.notice_verification_error_text'),
            ];
        } elseif (!empty($_GET['reset_requested'])) {
            $notice = [
                'type' => 'success',
                'title' => \t('auth.login.notice_reset_requested_title'),
                'message' => \t('auth.login.notice_reset_requested_text'),
            ];
        } elseif (!empty($_GET['reset_completed'])) {
            $notice = [
                'type' => 'success',
                'title' => \t('auth.login.notice_reset_completed_title'),
                'message' => \t('auth.login.notice_reset_completed_text'),
            ];
        } elseif (!empty($_GET['reset_error'])) {
            $notice = [
                'type' => 'warning',
                'title' => \t('auth.login.notice_reset_error_title'),
                'message' => \t('auth.login.notice_reset_error_text'),
            ];
        }

        return $this->render('user/login.html.twig', [
            'login_notice' => $notice,
            'login_stats' => $this->buildLoginStats(),
        ]);
    }

    public function showForgotPassword()
    {
        $this->redirectLoggedUsers();

        return $this->render('user/forgot_password.html.twig');
    }

    public function requestPasswordReset()
    {
        $email = self::normalizeEmail($_POST['email'] ?? '');
        $clientIp = $this->getClientIp();
        $fingerprint = $this->getRequestFingerprint();
        $scopes = array_filter([
            'password_reset_ip' => $clientIp,
            'password_reset_email' => $email,
            'password_reset_fingerprint' => $fingerprint,
        ]);

        $blocked = $this->assertRateLimitScopes($scopes, 'email');
        if ($blocked !== null) {
            return $blocked;
        }

        if ($email === '') {
            return $this->validationError('email', 'E-posta zorunludur.');
        }

        if (!self::isValidEmail($email)) {
            return $this->validationError('email', 'Gecerli bir e-posta adresi girin.');
        }

        $this->hitRateLimitScopes($scopes);

        $response = new \stdClass();
        $response->error = 0;
        $response->field = null;
        $response->message = \t('auth.forgot.success_message');

        $user = UserModel::where('email', $email)->first();
        if (empty($user)) {
            return $response;
        }

        DB::beginTransaction();
        try {
            $reset = $this->issuePasswordReset($user, $email, $clientIp);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new \Exception('Sifre sifirlama istegi olusturulamadi.', AppException::ACTION_FAILED);
        }

        $mailResult = $this->sendPasswordResetEmail($user, $reset['token'], $reset['expires_at']);
        if (!empty($mailResult['debug_reset_url'])) {
            $response->debug_reset_url = $mailResult['debug_reset_url'];
        }
        if (!empty($mailResult['debug_mail_file'])) {
            $response->debug_mail_file = $mailResult['debug_mail_file'];
        }

        return $response;
    }

    public function showResetPassword($token)
    {
        $this->redirectLoggedUsers();

        $resetRow = $this->findValidPasswordResetRow($token);
        if (!$resetRow) {
            $this->redirectToLoginWithQuery(['reset_error' => 1]);
            return;
        }

        return $this->render('user/reset_password.html.twig', [
            'reset_token' => (string) $token,
            'reset_email' => (string) ($resetRow->email ?? ''),
        ]);
    }

    public function resetPasswordWithToken($token)
    {
        $this->redirectLoggedUsers();

        $resetRow = $this->findValidPasswordResetRow($token);
        if (!$resetRow) {
            return $this->validationError('general', 'Sifre sifirlama baglantisi gecersiz veya suresi dolmus.', 'token_invalid');
        }

        $password = (string) ($_POST['password'] ?? '');
        $passwordRepeat = (string) ($_POST['password2'] ?? '');
        $user = UserModel::find((int) $resetRow->uid);
        if (empty($user)) {
            return $this->validationError('general', 'Hesap kaydi bulunamadi.', 'token_invalid');
        }

        $passwordError = self::validatePassword($password, $passwordRepeat, (string) $user->nick, (string) $user->email);
        if ($passwordError !== null) {
            $field = ($password === $passwordRepeat) ? 'password' : 'password2';
            return $this->validationError($field, $passwordError);
        }

        DB::beginTransaction();
        try {
            $user->password = self::hashPassword($password);
            $user->save();

            DB::table('user_password_resets')
                ->where('uid', (int) $user->id)
                ->whereNull('used_at')
                ->update([
                    'used_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if (DB::getSchemaBuilder()->hasTable('auth_remember_tokens')) {
                DB::table('auth_remember_tokens')->where('uid', (int) $user->id)->delete();
            }

            $this->clearRateLimitScopes($this->buildLoginRateLimitScopes($this->getClientIp(), self::normalizeEmail((string) $user->email), $this->getRequestFingerprint()));
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new \Exception('Sifre guncellenemedi.', AppException::ACTION_FAILED);
        }

        $response = new \stdClass();
        $response->error = 0;
        $response->field = null;
        $response->message = \t('auth.reset.success_message');
        $response->redirect = $this->app->getContainer()->get('router')->pathFor('login') . '?reset_completed=1';
        return $response;
    }

    private function buildLoginStats()
    {
        $stats = [
            'users' => 0,
            'countries' => 0,
        ];

        try {
            $schema = DB::getSchemaBuilder();
            if ($schema->hasTable('users')) {
                $stats['users'] = (int) DB::table('users')->count();
            }
            if ($schema->hasTable('countries')) {
                $stats['countries'] = (int) DB::table('countries')->count();
            }
        } catch (\Throwable $e) {
            return $stats;
        }

        return $stats;
    }

    public function setGuestLanguage()
    {
        $locale = trim((string) ($_POST['locale'] ?? ''));
        $langManager = $this->container->get('langManager');

        if (!$langManager->isSupported($locale)) {
            return $this->validationError('locale', \t('auth.language_unsupported'), 'validation');
        }

        $response = new \stdClass();
        $response->error = 0;
        $response->locale = $langManager->setLocale($locale);
        return $response;
    }

    public function checkNicknameAvailability()
    {
        $nick = self::normalizeNick($_POST["username"] ?? $_GET["username"] ?? "");
        $clientIp = $this->getClientIp();
        $fingerprint = $this->getRequestFingerprint();
        $scopes = [
            'nick_check_ip' => $clientIp,
            'nick_check_fingerprint' => $fingerprint,
        ];

        $blocked = $this->assertRateLimitScopes($scopes, "username");
        if ($blocked !== null) {
            return $blocked;
        }

        $this->hitRateLimitScopes($scopes);

        if ($nick === '') {
            return $this->validationError("username", "Kullanici adinizi yazin.");
        }

        $nickError = self::validateNickname($nick);
        if ($nickError !== null) {
            return $this->validationError("username", $nickError);
        }

        $response = new \stdClass();
        $response->error = 0;
        $response->field = "username";
        $response->available = !DB::table('users')->whereRaw('LOWER(nick) = ?', [strtolower($nick)])->exists();
        $response->message = $response->available ? "Bu kullanici adi uygun." : "Bu kullanici adi zaten alinmis.";
        return $response;
    }

    public function doLogin()
    {
        $email = self::normalizeEmail($_POST["email"] ?? "");
        $password = $_POST["password"] ?? "";
        $rememberMe = !empty($_POST["remember_me"]);
        $clientIp = $this->getClientIp();
        $fingerprint = $this->getRequestFingerprint();
        $loginScopes = $this->buildLoginRateLimitScopes($clientIp, $email, $fingerprint);
        $suspiciousScope = $this->buildActorScope($clientIp, $fingerprint);

        $blocked = $this->assertRateLimitScopes($loginScopes, "general");
        if ($blocked !== null) {
            return $blocked;
        }
        $blocked = $this->assertRateLimitScopes([
            'login_suspicious_actor' => $suspiciousScope,
        ], "general");
        if ($blocked !== null) {
            return $blocked;
        }

        if (empty($email)) {
            return $this->validationError("email", "E-posta zorunludur.");
        }
        if (!self::isValidEmail($email)) {
            return $this->validationError("email", "Gecerli bir e-posta adresi girin.");
        }
        if (empty($password)) {
            return $this->validationError("password", "Sifre zorunludur.");
        }

        $user = UserModel::where("email", $email)->first();

        if (empty($user) || !self::verifyPassword($user->password, $password)) {
            $this->hitRateLimitScopes($loginScopes);
            $this->hitRateLimitScopes([
                'login_suspicious_actor' => $suspiciousScope,
            ]);
            return $this->validationError("password", "E-posta veya sifre hatali.");
        }

        $this->clearRateLimitScopes($loginScopes);
        $this->clearRateLimitScopes([
            'login_suspicious_actor' => $suspiciousScope,
        ]);

        if (self::shouldUpgradePasswordHash($user->password)) {
            $user->password = self::hashPassword($password);
            $user->save();
        }

        App::session()->fillUserData($user->toArray(), true);
        if ($rememberMe) {
            App::session()->issueRememberToken((int) $user->id);
        } else {
            App::session()->clearRememberToken();
        }

        $response = new \stdClass();
        $response->error = 0;
        $response->field = null;
        return $response;
    }

    public function resendVerificationEmail()
    {
        $email = self::normalizeEmail($_POST["email"] ?? "");
        $clientIp = $this->getClientIp();

        if ($email === '' || !self::isValidEmail($email)) {
            throw new \Exception("Gecerli bir e-posta adresi girin.", AppException::INVALID_DATA);
        }

        $this->enforceRateLimit('verify_resend_ip', $clientIp);
        $this->enforceRateLimit('verify_resend_email', $email);

        $user = UserModel::where("email", $email)->first();
        if (empty($user)) {
            throw new \Exception("Bu e-posta adresi ile bekleyen hesap bulunamadi.", AppException::INVALID_DATA);
        }
        if ((int) $user->status === UserModel::STATUS_ACTIVATED) {
            throw new \Exception("Bu hesap zaten dogrulanmis.", AppException::INVALID_DATA);
        }

        DB::beginTransaction();
        try {
            $verification = $this->issueEmailVerification($user, $email, $clientIp);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new \Exception("Dogrulama baglantisi yeniden uretilemedi.", AppException::ACTION_FAILED);
        }

        $mailResult = $this->sendVerificationEmail($user, $verification['token'], $verification['expires_at']);

        $response = new \stdClass();
        $response->error = 0;
        $response->message = "Dogrulama baglantisi yeniden gonderildi.";
        if (!empty($mailResult['debug_verify_url'])) {
            $response->debug_verify_url = $mailResult['debug_verify_url'];
        }
        return $response;
    }

    public function verifyEmail($token)
    {
        $token = trim((string) $token);
        $this->ensureAuthSupportTables();

        if ($token === '' || strlen($token) < 32) {
            $this->redirectToLoginWithQuery(['verification_error' => 1]);
        }

        $record = DB::table('user_email_verifications')
            ->where('token_hash', hash('sha256', $token))
            ->orderBy('id', 'desc')
            ->first();

        if (empty($record) || !empty($record->verified_at) || strtotime((string) $record->expires_at) < time()) {
            $this->redirectToLoginWithQuery([
                'verification_error' => 1,
                'email' => $record->email ?? '',
            ]);
        }

        $user = UserModel::find((int) $record->uid);
        if (empty($user)) {
            $this->redirectToLoginWithQuery(['verification_error' => 1]);
        }

        DB::beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            DB::table('user_email_verifications')
                ->where('uid', (int) $record->uid)
                ->whereNull('verified_at')
                ->update(['verified_at' => $now, 'updated_at' => $now]);
            $user->status = UserModel::STATUS_ACTIVATED;
            $user->email_verified_at = $now;
            $user->save();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->redirectToLoginWithQuery(['verification_error' => 1, 'email' => $user->email]);
        }

        $this->redirectToLoginWithQuery(['verified' => 1, 'email' => $user->email]);
    }

    public function logout()
    {
        $this->app->getContainer()->get("session")->logout();
    }

    private function redirectLoggedUsers()
    {
        if ($this->isLogged) {
            App::redirect($this->app->getContainer()->get('router')->pathFor('home'));
            exit;
        }
    }

    private static function isValidEmail($email)
    {
        return !!filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private static function normalizeEmail($email)
    {
        $email = trim((string) $email);
        $email = strip_tags($email);
        return strtolower($email);
    }

    private static function normalizeNick($nick)
    {
        $nick = trim((string) $nick);
        $nick = strip_tags($nick);
        return $nick;
    }

    private static function validateNickname($nick)
    {
        $canonicalNick = strtolower($nick);
        $length = strlen($nick);

        if ($length < UserModel::MIN_NICK_LENGTH || $length > UserModel::MAX_NICK_LENGTH) {
            return "Kullanici adi 4 ile 20 karakter arasinda olmali.";
        }

        if (!preg_match('/^(?=.{4,20}$)(?=.*[A-Za-z])[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*$/', $nick)) {
            return "Kullanici adi sadece harf, rakam ve tekli alt cizgi icerebilir.";
        }

        if (in_array($canonicalNick, self::RESERVED_NICK_EXACT, true)) {
            return "Bu kullanici adi kullanilamaz.";
        }

        foreach (self::RESERVED_NICK_PARTIAL as $blockedWord) {
            if (strpos($canonicalNick, $blockedWord) !== false) {
                return "Kullanici adi sistem kelimeleri iceremez.";
            }
        }

        foreach (self::BLOCKED_NICK_WORDS as $blockedWord) {
            if (strpos($canonicalNick, $blockedWord) !== false) {
                return "Kullanici adi uygun olmayan ifade iceriyor.";
            }
        }

        if (preg_match('/^\d+$/', $canonicalNick)) {
            return "Kullanici adi sadece rakamlardan olusamaz.";
        }

        return null;
    }

    private static function validatePassword($password, $passwordRepeat, $nick, $email)
    {
        $length = strlen($password);

        if ($password !== $passwordRepeat) {
            return "Şifre tekrarı uyuşmuyor.";
        }

        if ($length < UserModel::MIN_PASSWORD_LENGTH) {
            return "Şifre en az 10 karakter olmalı.";
        }

        if ($length > UserModel::MAX_PASSWORD_LENGTH) {
            return "Şifre çok uzun.";
        }

        if (preg_match('/\s/', $password)) {
            return "Şifre boşluk içeremez.";
        }

        if (in_array(strtolower($password), self::COMMON_PASSWORDS, true)) {
            return "Bu şifre çok yaygın. Daha güçlü bir şifre seçin.";
        }

        $emailLocal = explode('@', $email)[0] ?? '';
        $comparisonTargets = [$nick, $emailLocal];

        foreach ($comparisonTargets as $target) {
            $target = strtolower(trim((string) $target));
            if (strlen($target) >= 3 && strpos(strtolower($password), $target) !== false) {
                return "Şifre kullanıcı adı veya e-posta bilgisi içeremez.";
            }
        }

        return null;
    }

    private static function verifyPassword($storedHash, $password)
    {
        if (self::isModernPasswordHash($storedHash)) {
            return password_verify($password, $storedHash);
        }

        return hash_equals((string) $storedHash, self::encryptPassword($password));
    }

    private static function shouldUpgradePasswordHash($storedHash)
    {
        if (!self::isModernPasswordHash($storedHash)) {
            return true;
        }

        if (defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($storedHash, PASSWORD_ARGON2ID);
        }

        return password_needs_rehash($storedHash, PASSWORD_BCRYPT);
    }

    private static function isModernPasswordHash($storedHash)
    {
        $info = password_get_info((string) $storedHash);
        return !empty($info["algo"]);
    }

    private function resolveSignupRegionId($country)
    {
        $capitalRegionId = (int) ($country->capital ?? 0);

        if ($capitalRegionId > 0) {
            $capitalRegion = DB::table('regions')->where('id', $capitalRegionId)->first();
            if (!empty($capitalRegion)) {
                return (int) $capitalRegion->id;
            }
        }

        $fallbackRegion = DB::table('regions')
            ->where('country', (int) $country->id)
            ->orderBy('id', 'asc')
            ->first();

        return (int) ($fallbackRegion->id ?? 0);
    }

    private function issueEmailVerification($user, $email, $clientIp)
    {
        $this->ensureAuthSupportTables();

        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::EMAIL_VERIFICATION_EXPIRES_HOURS . ' hours'));

        DB::table('user_email_verifications')
            ->where('uid', (int) $user->id)
            ->whereNull('verified_at')
            ->update(['updated_at' => $now, 'verified_at' => $now]);

        DB::table('user_email_verifications')->insert([
            'uid' => (int) $user->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'requested_ip' => substr($clientIp, 0, 45),
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function issuePasswordReset($user, $email, $clientIp)
    {
        $this->ensurePasswordResetSupportTables();

        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::PASSWORD_RESET_EXPIRES_HOURS . ' hours'));

        DB::table('user_password_resets')
            ->where('uid', (int) $user->id)
            ->whereNull('used_at')
            ->update(['updated_at' => $now, 'used_at' => $now]);

        DB::table('user_password_resets')->insert([
            'uid' => (int) $user->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'requested_ip' => substr($clientIp, 0, 45),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    private function sendVerificationEmail($user, $token, $expiresAt)
    {
        $verifyUrl = $this->buildVerificationUrl($token);
        $subject = 'S-REPUBLIK e-posta dogrulamasi';
        $message = "Merhaba {$user->nick},\n\nHesabini dogrulamak icin asagidaki baglantiyi ac:\n{$verifyUrl}\n\nBaglantinin son gecerlilik tarihi: {$expiresAt}\n";
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: S-REPUBLIK <no-reply@localhost>',
        ];

        $sent = false;
        if (function_exists('mail') && App::settings()['mode'] !== 'development') {
            $sent = @mail($user->email, $subject, $message, implode("\r\n", $headers));
        }

        $result = ['sent' => $sent];
        if ($this->shouldWriteAuthDebugMail() || !$sent) {
            $mailDir = APP_ROOT . 'tmp/mail';
            if (!is_dir($mailDir)) {
                @mkdir($mailDir, 0777, true);
            }
            $mailFile = $mailDir . '/verify_' . (int) $user->id . '.txt';
            @file_put_contents($mailFile, $message);
            $result['debug_verify_url'] = $verifyUrl;
            $result['debug_mail_file'] = $mailFile;
        }

        return $result;
    }

    private function sendPasswordResetEmail($user, $token, $expiresAt)
    {
        $resetUrl = $this->buildPasswordResetUrl($token);
        $subject = 'Project V26 sifre sifirlama';
        $message = "Merhaba {$user->nick},\n\nSifreni yenilemek icin asagidaki baglantiyi ac:\n{$resetUrl}\n\nBaglantinin son gecerlilik tarihi: {$expiresAt}\n";
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: Project V26 <no-reply@localhost>',
        ];

        $sent = false;
        if (function_exists('mail') && App::settings()['mode'] !== 'development') {
            $sent = @mail($user->email, $subject, $message, implode("\r\n", $headers));
        }

        $result = ['sent' => $sent];
        if ($this->shouldWriteAuthDebugMail() || !$sent) {
            $mailDir = APP_ROOT . 'tmp/mail';
            if (!is_dir($mailDir)) {
                @mkdir($mailDir, 0777, true);
            }
            $mailFile = $mailDir . '/reset_' . (int) $user->id . '.txt';
            @file_put_contents($mailFile, $message);
            $result['debug_reset_url'] = $resetUrl;
            $result['debug_mail_file'] = $mailFile;
        }

        return $result;
    }

    private function buildVerificationUrl($token)
    {
        $uri = $this->container->get('request')->getUri();
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        return $base . $this->app->getContainer()->get('router')->pathFor('verifyEmail', ['token' => $token]);
    }

    private function buildPasswordResetUrl($token)
    {
        $uri = $this->container->get('request')->getUri();
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        return $base . $this->app->getContainer()->get('router')->pathFor('resetPassword', ['token' => $token]);
    }

    private function findValidPasswordResetRow($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        try {
            $this->ensurePasswordResetSupportTables();
        } catch (\Throwable $e) {
            return null;
        }

        return DB::table('user_password_resets')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->first();
    }

    private function shouldWriteAuthDebugMail()
    {
        if (App::settings()['mode'] === 'development') {
            return true;
        }

        $host = strtolower((string) ($this->container->get('request')->getUri()->getHost() ?? ''));
        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    private function redirectToLoginWithQuery(array $params = [])
    {
        $target = $this->app->getContainer()->get('router')->pathFor('login');
        if (!empty($params)) {
            $target .= '?' . http_build_query($params);
        }
        App::redirect($target);
    }

    private function getClientIp()
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }

    private function isDisposableEmailDomain($email)
    {
        $domain = strtolower((string) (substr(strrchr($email, '@'), 1) ?: ''));
        return $domain !== '' && in_array($domain, self::DISPOSABLE_EMAIL_DOMAINS, true);
    }

    private function validationError($field, $message, $code = 'validation')
    {
        $response = new \stdClass();
        $response->error = 1;
        $response->field = $field;
        $response->code = $code;
        $response->message = $message;
        return $response;
    }

    private function getRequestFingerprint()
    {
        $raw = trim((string) ($_POST['fingerprint'] ?? $_GET['fingerprint'] ?? ''));
        if ($raw === '') {
            return '';
        }

        return hash('sha256', substr($raw, 0, 500));
    }

    private function buildActorScope($clientIp, $fingerprint)
    {
        if ($clientIp === '' && $fingerprint === '') {
            return '';
        }

        return $clientIp . '|' . $fingerprint;
    }

    private function buildLoginRateLimitScopes($clientIp, $email, $fingerprint)
    {
        $actor = $this->buildActorScope($clientIp, $fingerprint);

        return array_filter([
            'login_ip' => $clientIp,
            'login_email' => $email,
            'login_fingerprint' => $fingerprint,
            'login_actor' => $actor,
            'login_email_actor' => ($email !== '' && $actor !== '') ? ($email . '|' . $actor) : '',
        ]);
    }

    private function buildSignupRateLimitScopes($clientIp, $email, $fingerprint)
    {
        $actor = $this->buildActorScope($clientIp, $fingerprint);

        return array_filter([
            'signup_ip' => $clientIp,
            'signup_email' => $email,
            'signup_fingerprint' => $fingerprint,
            'signup_actor' => $actor,
            'signup_email_actor' => ($email !== '' && $actor !== '') ? ($email . '|' . $actor) : '',
        ]);
    }

    private function ensureAuthSupportTables()
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('user_email_verifications') || !$schema->hasTable('auth_rate_limits')) {
            throw new \Exception('Auth schema sync SQL henuz uygulanmamis.', AppException::ACTION_FAILED);
        }
    }

    private function ensurePasswordResetSupportTables()
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('user_password_resets') || !$schema->hasTable('auth_rate_limits')) {
            throw new \Exception('Password reset schema sync SQL henuz uygulanmamis.', AppException::ACTION_FAILED);
        }
    }

    private function assertRateLimitScopes(array $scopes, $field = 'general')
    {
        foreach ($scopes as $action => $scopeKey) {
            $blocked = $this->assertRateLimit($action, $scopeKey);
            if ($blocked !== null) {
                return $this->validationError($field, $blocked, 'rate_limit');
            }
        }

        return null;
    }

    private function hitRateLimitScopes(array $scopes)
    {
        foreach ($scopes as $action => $scopeKey) {
            $this->hitRateLimit($action, $scopeKey);
        }
    }

    private function clearRateLimitScopes(array $scopes)
    {
        foreach ($scopes as $action => $scopeKey) {
            $this->clearRateLimit($action, $scopeKey);
        }
    }

    private function assertRateLimit($action, $scopeKey)
    {
        if (empty($scopeKey)) {
            return null;
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('auth_rate_limits') || !isset(self::RATE_LIMITS[$action])) {
            return null;
        }

        $row = DB::table('auth_rate_limits')->where('action', $action)->where('scope_key', $scopeKey)->first();

        if (!empty($row) && !empty($row->blocked_until) && strtotime((string) $row->blocked_until) > time()) {
            if ($action === 'login_suspicious_actor') {
                return 'Supheli giris denemeleri nedeniyle gecici bekleme uygulandi. Lutfen daha sonra tekrar deneyin.';
            }

            return 'Cok fazla deneme yaptiniz. Lutfen daha sonra tekrar deneyin.';
        }

        return null;
    }

    private function hitRateLimit($action, $scopeKey)
    {
        if (empty($scopeKey)) {
            return;
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('auth_rate_limits') || !isset(self::RATE_LIMITS[$action])) {
            return;
        }

        $config = self::RATE_LIMITS[$action];
        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        $windowStartedAt = date('Y-m-d H:i:s', $nowTs - (int) $config['window']);
        $row = DB::table('auth_rate_limits')->where('action', $action)->where('scope_key', $scopeKey)->first();

        if (empty($row) || strtotime((string) $row->window_started_at) <= strtotime($windowStartedAt)) {
            DB::table('auth_rate_limits')->updateOrInsert(
                ['action' => $action, 'scope_key' => $scopeKey],
                [
                    'attempts' => 1,
                    'window_started_at' => $now,
                    'last_attempt_at' => $now,
                    'blocked_until' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            return;
        }

        $attempts = ((int) $row->attempts) + 1;
        $payload = ['attempts' => $attempts, 'last_attempt_at' => $now, 'updated_at' => $now];
        if ($attempts > (int) $config['limit']) {
            $payload['blocked_until'] = date('Y-m-d H:i:s', $nowTs + (int) $config['block']);
        }

        DB::table('auth_rate_limits')->where('id', (int) $row->id)->update($payload);
    }

    private function clearRateLimit($action, $scopeKey)
    {
        if (empty($scopeKey)) {
            return;
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('auth_rate_limits')) {
            return;
        }

        DB::table('auth_rate_limits')
            ->where('action', $action)
            ->where('scope_key', $scopeKey)
            ->delete();
    }

    private function grantEconomicWorkProgress($uid, $xpGain = 1)
    {
        $user = UserModel::where([
            "id" => $uid
        ])->first();

        if (empty($user)) {
            return false;
        }

        $user->economic_skill = max(1, (int) ($user->economic_skill ?? 1));
        $user->economic_xp = max(0, (int) ($user->economic_xp ?? 0));
        $user->economic_xp += max(0, (int) $xpGain);

        while ($user->economic_xp >= $this->getEconomicSkillRequiredXp($user->economic_skill)) {
            $requiredXp = $this->getEconomicSkillRequiredXp($user->economic_skill);
            $user->economic_xp -= $requiredXp;
            $user->economic_skill++;
        }

        return $user->save();
    }

    private function getEconomicSkillRequiredXp($currentSkill)
    {
        $currentSkill = max(1, (int) $currentSkill);
        return $currentSkill * 3;
    }
}




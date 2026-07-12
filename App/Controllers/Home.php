<?php

namespace App\Controllers;

use App\Models\UserGym;
use App\Models\UserItem;
use App\System\App;
use App\System\Controller;
use App\System\GameExperience;
use Illuminate\Database\Capsule\Manager as DB;

class Home extends Controller
{
    private function relativeTimeLabel($value)
    {
        static $translator = null;

        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime((string) $value);
        if (!$timestamp) {
            return (string) $value;
        }

        $diff = time() - $timestamp;
        if ($diff < 0) {
            return date('d.m.Y H:i', $timestamp);
        }

        if ($translator === null) {
            try {
                $translator = $this->container->get('i18n')->getTranslator();
            } catch (\Exception $e) {
                $translator = false;
            }
        }

        $translate = function ($key) use ($translator) {
            if ($translator) {
                return $translator->translate($key);
            }

            static $fallback = null;
            if ($fallback === null) {
                $fallback = require APP_ROOT . 'lang/tr.php';
            }

            return $fallback[$key] ?? $key;
        };

        if ($diff < 60) {
            return 'az once';
        }

        $minutes = (int) floor($diff / 60);
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

    /**
     * Show home page
     * @return mixed
     */
    public function showHomepage ()
    {
        if (!$this->isLogged) {
            App::redirect($this->app->getContainer()->get('router')->pathFor('home'));
            exit;
        }

        $session = App::session();

        /**
         * Check if user has job and has worked today
         */
        $job = $session->getJob();

        $jobData = [
            "hasJob" => false,
            "hasWorkedToday" => false
        ];

        if (!empty($job)) {
            $jobData["hasJob"] = true;
            $jobData["hasWorkedToday"] = $job->hasWorkedToday();
        }

        $uid = (int) $session->getUid();
        $sessionUser = $session->getUser();

        $userGyms = UserGym::where([
            "uid" => $uid
        ])->first();
        $hasAnyTrainingToday = UserGym::hasTrainingTodayForUser($uid, $userGyms);

        $location = $session->getLocation();
        $countryId = (int) ($location["country"]["id"] ?? 0);
        $countryCurrency = strtoupper((string) ($location["country"]["currency"] ?? ""));

        $latestArticles = [];
        $todaySummaryArticles = [];
        $newsFeeds = [
            'all' => [],
            'social' => [],
            'political' => [],
            'economy' => [],
            'military' => [],
        ];
        $shouts = [];

        $normalizeNewsRows = function ($rows) {
            return array_map(function ($row) {
                $item = (array) $row;
                $title = mb_strtolower((string) ($item['title'] ?? ''), 'UTF-8');
                $isAdminSource = (int) ($item['author_is_admin'] ?? 0) === 1;
                $criticalType = '';
                if ($isAdminSource) {
                    if (preg_match('/bakim|bakım|maintenance/u', $title)) {
                        $criticalType = 'maintenance';
                    } elseif (preg_match('/guncelleme|güncelleme|update/u', $title)) {
                        $criticalType = 'update';
                    } elseif (preg_match('/kritik|duyuru|announcement|critical/u', $title)) {
                        $criticalType = 'critical';
                    }
                }
                $item['is_system_critical'] = $criticalType !== '';
                $item['critical_type'] = $criticalType;
                return $item;
            }, $rows ? (array) $rows : []);
        };

        $loadNewsFeed = function ($builder) {
            return $builder->limit(12)->get([
                'a.id',
                'a.title',
                'a.created_at',
                'a.votes',
                'a.category',
                'u.nick as author_nick',
                'u.is_admin as author_is_admin',
            ])->toArray();
        };

        try {
            $newsFeeds['all'] = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->orderBy('a.created_at', 'desc')
            ));

            $newsFeeds['social'] = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->where('a.category', 4)
                    ->orderBy('a.created_at', 'desc')
            ));

            $newsFeeds['political'] = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->where('a.category', 1)
                    ->orderBy('a.created_at', 'desc')
            ));

            $newsFeeds['economy'] = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->where('a.category', 2)
                    ->orderBy('a.created_at', 'desc')
            ));

            $newsFeeds['military'] = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->where('a.category', 3)
                    ->orderBy('a.created_at', 'desc')
            ));

            $latestArticles = $newsFeeds['all'];
            $todaySummaryArticles = $normalizeNewsRows($loadNewsFeed(
                DB::table('newspaper_articles as a')
                    ->leftJoin('users as u', 'u.id', '=', 'a.uid')
                    ->whereRaw('DATE(a.created_at) = ?', [date('Y-m-d')])
                    ->orderBy('a.votes', 'desc')
                    ->orderBy('a.created_at', 'desc')
            ));
            $todaySummaryArticles = array_slice($todaySummaryArticles, 0, 3);
        } catch (\Exception $e) {
            $latestArticles = [];
            $todaySummaryArticles = [];
            $newsFeeds = [
                'all' => [],
                'social' => [],
                'political' => [],
                'economy' => [],
                'military' => [],
            ];
        }

        $isAdmin = false;
        if (array_key_exists('is_admin', (array) $sessionUser)) {
            $isAdmin = (int) ($sessionUser['is_admin'] ?? 0) === 1;
        } else {
            try {
                $isAdmin = (int) DB::table('users')->where('id', $uid)->value('is_admin') === 1;
            } catch (\Exception $e) {
                $isAdmin = false;
            }
        }

        $myPartyMembership = $session->getPoliticalParty();
        $hasPoliticalParty = $myPartyMembership ? true : false;
        $myNewspaper = $session->getNewspaper();
        $hasAvatar = !empty($sessionUser["avatar"]);
        $hasProfileName = trim((string) ($sessionUser["nick"] ?? $sessionUser["username"] ?? $sessionUser["name"] ?? '')) !== '';
        $hasProfileLocation = (int) ($sessionUser["region"] ?? 0) > 0 || (int) ($sessionUser["country_id"] ?? 0) > 0 || $countryId > 0;
        $hasCompletedProfile = $hasAvatar && $hasProfileName && $hasProfileLocation;
        $goldBalance = 0.0;

        $localBalance = 0.0;
        $money = $session->getMoney();
        if ($money) {
            $goldBalance = (float) ($money->gold ?? 0);
            if ($countryCurrency !== '') {
                $localBalance = (float) ($money->{$countryCurrency} ?? 0);
            }
        }

        $hasInventoryItem = false;
        try {
            $hasInventoryItem = UserItem::where('uid', $uid)->where('quantity', '>', 0)->exists();
        } catch (\Exception $e) {
            $hasInventoryItem = false;
        }

        $pendingPartyApplications = 0;
        if ($hasPoliticalParty && (int) ($myPartyMembership->level ?? 0) === 3) {
            try {
                $pendingPartyApplications = (int) DB::table('party_join_applications')
                    ->where('party_id', (int) $myPartyMembership->party)
                    ->where('status', 'pending')
                    ->count();
            } catch (\Exception $e) {
                $pendingPartyApplications = 0;
            }
        }

        $initialShoutFeed = [
            'mode' => 'latest',
            'items' => [],
            'total' => 0,
            'has_more' => false,
            'next_offset' => 0,
            'reported_total' => 0,
        ];
        $presidentMessage = null;

        try {
            if (\App\Controllers\Shout::featuresSchemaReady()) {
                $initialShoutFeed = \App\Controllers\Shout::fetchFeedSlice($uid, 8, 0, 'latest', $isAdmin);
                $shouts = $initialShoutFeed['items'];

                if ($countryId > 0) {
                    $presidentUid = (int) DB::table('countries')->where('id', $countryId)->value('president');
                    if ($presidentUid > 0) {
                        $now = date('Y-m-d H:i:s');
                            $presidentRow = DB::table('shouts as s')
                            ->leftJoin('users as u', 'u.id', '=', 's.uid')
                            ->leftJoin(DB::raw('(SELECT uid, MIN(party) AS party_id FROM party_members GROUP BY uid) pm'), 'pm.uid', '=', 's.uid')
                            ->leftJoin('political_parties as p', 'p.id', '=', 'pm.party_id')
                            ->where('s.uid', $presidentUid)
                            ->where('s.is_deleted', 0)
                            ->where('s.is_state_decree', 1)
                            ->where('s.decree_country_id', $countryId)
                            ->whereNotNull('s.decree_expires_at')
                            ->where('s.decree_expires_at', '>', $now)
                            ->orderBy('s.id', 'desc')
                            ->first([
                                's.id',
                                's.body',
                                's.created_at',
                                's.likes_count',
                                's.is_state_decree',
                                's.decree_country_id',
                                's.decree_expires_at',
                                'u.nick',
                                'u.avatar',
                                'p.name as party_name',
                                'p.logo_url as party_logo_url',
                            ]);

                        if ($presidentRow) {
                            $presidentShoutId = (int) ($presidentRow->id ?? 0);
                            $replyCount = 0;
                            $likedByMe = false;

                            if ($presidentShoutId > 0) {
                                try {
                                    $replyCount = (int) DB::table('shouts')
                                        ->where('parent_id', $presidentShoutId)
                                        ->where('is_deleted', 0)
                                        ->count();

                                    if ($uid > 0) {
                                        $likedByMe = DB::table('shout_likes')
                                            ->where('shout_id', $presidentShoutId)
                                            ->where('uid', $uid)
                                            ->exists();
                                    }
                                } catch (\Exception $e) {
                                    $replyCount = 0;
                                    $likedByMe = false;
                                }
                            }

                            $presidentMessage = [
                                'id' => $presidentShoutId,
                                'uid' => $presidentUid,
                                'body' => trim((string) ($presidentRow->body ?? '')),
                                'body_html' => \App\Controllers\Shout::buildDisplayBodyHtml($presidentRow->body ?? ''),
                                'created_at_label' => $this->relativeTimeLabel($presidentRow->created_at ?? ''),
                                'likes_count' => (int) ($presidentRow->likes_count ?? 0),
                                'liked_by_me' => $likedByMe,
                                'reply_count' => $replyCount,
                                'nick' => (string) ($presidentRow->nick ?? 'Baskan'),
                                'avatar' => (string) ($presidentRow->avatar ?? ''),
                                'country_id' => $countryId,
                                'party_name' => (string) ($presidentRow->party_name ?? ''),
                                'party_logo_url' => (string) ($presidentRow->party_logo_url ?? ''),
                                'is_state_decree' => true,
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $shouts = [];
            $initialShoutFeed['items'] = [];
            $presidentMessage = null;
        }

        $shoutComposerMeta = \App\Controllers\Shout::getComposerMeta($uid);

        $onboardingSteps = [
            [
                'key' => 'profile',
                'stage' => 'start',
                'icon' => 'fa-id-card',
                'optional' => false,
                'daily' => false,
                'label' => \t('home.route.step_1_label'),
                'done' => $hasCompletedProfile,
                'href' => $this->app->getContainer()->get('router')->pathFor('settings'),
                'hint' => $hasCompletedProfile ? \t('home.route.step_1_hint_done') : \t('home.route.step_1_hint_pending'),
                'reason' => \t('home.route.step_1_reason'),
                'benefit' => \t('home.route.step_1_benefit'),
            ],
            [
                'key' => 'training',
                'stage' => 'growth',
                'icon' => 'fa-dumbbell',
                'optional' => false,
                'daily' => true,
                'label' => \t('home.route.step_2_label'),
                'done' => $hasAnyTrainingToday,
                'href' => $this->app->getContainer()->get('router')->pathFor('gyms'),
                'hint' => $hasAnyTrainingToday ? \t('home.route.step_2_hint_done') : \t('home.route.step_2_hint_pending'),
                'reason' => \t('home.route.step_2_reason'),
                'benefit' => \t('home.route.step_2_benefit'),
            ],
            [
                'key' => 'work',
                'stage' => 'economy',
                'icon' => 'fa-briefcase',
                'optional' => false,
                'daily' => false,
                'label' => \t('home.route.step_3_label'),
                'done' => !empty($job),
                'href' => $this->app->getContainer()->get('router')->pathFor('workOffers'),
                'hint' => !empty($job) ? \t('home.route.step_3_hint_done') : \t('home.route.step_3_hint_pending'),
                'reason' => \t('home.route.step_3_reason'),
                'benefit' => \t('home.route.step_3_benefit'),
            ],
            [
                'key' => 'inventory',
                'stage' => 'economy',
                'icon' => 'fa-box-archive',
                'optional' => false,
                'daily' => false,
                'label' => \t('home.route.step_4_label'),
                'done' => $hasInventoryItem,
                'href' => $this->app->getContainer()->get('router')->pathFor('marketplace'),
                'hint' => $hasInventoryItem ? \t('home.route.step_4_hint_done') : \t('home.route.step_4_hint_pending'),
                'reason' => \t('home.route.step_4_reason'),
                'benefit' => \t('home.route.step_4_benefit'),
            ],
            [
                'key' => 'party',
                'stage' => 'politics',
                'icon' => 'fa-landmark-dome',
                'optional' => false,
                'daily' => false,
                'label' => \t('home.route.step_5_label'),
                'done' => $hasPoliticalParty,
                'href' => $this->app->getContainer()->get('router')->pathFor('partyList'),
                'hint' => $hasPoliticalParty ? \t('home.route.step_5_hint_done') : \t('home.route.step_5_hint_pending'),
                'reason' => \t('home.route.step_5_reason'),
                'benefit' => \t('home.route.step_5_benefit'),
            ],
            [
                'key' => 'newspaper',
                'stage' => 'social',
                'icon' => 'fa-newspaper',
                'optional' => true,
                'daily' => false,
                'label' => \t('home.route.step_6_label'),
                'done' => !empty($myNewspaper),
                'href' => $this->app->getContainer()->get('router')->pathFor('createNewspaper'),
                'hint' => !empty($myNewspaper) ? \t('home.route.step_6_hint_done') : \t('home.route.step_6_hint_pending'),
                'reason' => \t('home.route.step_6_reason'),
                'benefit' => \t('home.route.step_6_benefit'),
            ],
        ];

        $completedOnboarding = 0;
        $onboardingCoreTotal = 0;
        $completedOnboardingCore = 0;
        $onboardingNextKey = null;
        foreach ($onboardingSteps as $step) {
            if (!empty($step['done'])) {
                $completedOnboarding++;
            }
            if (empty($step['optional']) && empty($step['daily'])) {
                $onboardingCoreTotal++;
                if (!empty($step['done'])) {
                    $completedOnboardingCore++;
                } elseif ($onboardingNextKey === null) {
                    $onboardingNextKey = $step['key'];
                }
            }
        }
        $onboardingTotal = $onboardingCoreTotal;
        $onboardingProgress = $onboardingCoreTotal > 0 ? (int) floor(($completedOnboardingCore / $onboardingCoreTotal) * 100) : 0;
        $onboardingComplete = $onboardingCoreTotal > 0 && $completedOnboardingCore === $onboardingCoreTotal;

        $cronEntries = [];
        if ($isAdmin) {
            foreach (glob(APP_ROOT . 'crons/*.php') ?: [] as $cronFile) {
                $cronEntries[] = [
                    'name' => basename($cronFile),
                    'updated_at' => date('Y-m-d H:i:s', filemtime($cronFile)),
                ];
            }
            usort($cronEntries, function ($a, $b) {
                return strcmp((string) $b['updated_at'], (string) $a['updated_at']);
            });
            $cronEntries = array_slice($cronEntries, 0, 3);
        }

        $gameExperienceSettings = GameExperience::getPreferences((int) $uid);
        $router = $this->app->getContainer()->get('router');

        $playerGoals = [
            [
                'key' => 'profile',
                'icon' => 'fa-id-card',
                'label' => \t('home.player_goals.profile.label'),
                'description' => \t('home.player_goals.profile.description'),
                'done' => $hasCompletedProfile,
                'href' => $router->pathFor('settings'),
            ],
            [
                'key' => 'career',
                'icon' => 'fa-briefcase',
                'label' => \t('home.player_goals.career.label'),
                'description' => \t('home.player_goals.career.description'),
                'done' => !empty($job),
                'href' => $router->pathFor('workOffers'),
            ],
            [
                'key' => 'party',
                'icon' => 'fa-landmark-dome',
                'label' => \t('home.player_goals.party.label'),
                'description' => \t('home.player_goals.party.description'),
                'done' => $hasPoliticalParty,
                'href' => $hasPoliticalParty
                    ? $router->pathFor('party', ['id' => (int) ($myPartyMembership->party ?? 0)])
                    : $router->pathFor('partyList'),
            ],
        ];

        if ($hasPoliticalParty) {
            $partyRoleLevel = (int) ($myPartyMembership->level ?? 0);
            $playerGoals[] = [
                'key' => 'party_role',
                'icon' => 'fa-user-tie',
                'label' => \t('home.player_goals.party_role.label'),
                'description' => \t('home.player_goals.party_role.description'),
                'done' => $partyRoleLevel > 0,
                'href' => $router->pathFor('party', ['id' => (int) ($myPartyMembership->party ?? 0)]),
            ];
        }

        if ($hasPoliticalParty) {
            try {
                $schema = DB::getSchemaBuilder();
                $hasElectionTables = $schema->hasTable('party_elections')
                    && $schema->hasTable('party_candidates')
                    && $schema->hasTable('party_votes');

                if ($hasElectionTables) {
                    $partyElectionQuery = DB::table('party_elections')
                        ->where('party_id', (int) $myPartyMembership->party)
                        ->where('status', '!=', 'finished');
                    if ($schema->hasColumn('party_elections', 'election_key')) {
                        $partyElectionQuery->where('election_key', date('Y-m-01 00:00:00'));
                    }
                    $partyElection = $partyElectionQuery->orderBy('id', 'desc')->first();

                    if ($partyElection) {
                        $electionId = (int) $partyElection->id;
                        $isCandidate = DB::table('party_candidates')
                            ->where('election_id', $electionId)
                            ->where('uid', $uid)
                            ->exists();
                        $hasVoted = DB::table('party_votes')
                            ->where('election_id', $electionId)
                            ->where('voter_uid', $uid)
                            ->exists();
                        $electionStatus = (string) ($partyElection->status ?? '');

                        if ($electionStatus === 'candidacy' || $isCandidate) {
                            $playerGoals[] = [
                                'key' => 'election_candidate',
                                'icon' => 'fa-person-running',
                                'label' => \t('home.player_goals.election_candidate.label'),
                                'description' => \t('home.player_goals.election_candidate.description'),
                                'done' => $isCandidate,
                                'href' => $router->pathFor('electionsHome'),
                            ];
                        }
                        if ($electionStatus === 'voting' || $hasVoted) {
                            $playerGoals[] = [
                                'key' => 'election_vote',
                                'icon' => 'fa-check-to-slot',
                                'label' => \t('home.player_goals.election_vote.label'),
                                'description' => \t('home.player_goals.election_vote.description'),
                                'done' => $hasVoted,
                                'href' => $router->pathFor('electionsHome'),
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Election goals remain hidden when their tables are unavailable.
            }
        }

        $nextPlayerGoal = true;
        foreach ($playerGoals as &$playerGoal) {
            $playerGoal['is_next'] = false;
            if (!$playerGoal['done'] && $nextPlayerGoal) {
                $playerGoal['is_next'] = true;
                $nextPlayerGoal = false;
            }
        }
        unset($playerGoal);

        $quickLinks = GameExperience::buildQuickLinks($gameExperienceSettings, function ($routeName) use ($router) {
            return $router->pathFor($routeName);
        });

        return $this->render('home.html.twig', [
            "job" => $jobData,
            "hasTrainedToday" => $hasAnyTrainingToday,
            "hasPoliticalParty" => $hasPoliticalParty,
            "latestArticles" => $latestArticles,
            "newsFeeds" => $newsFeeds,
            "todaySummaryArticles" => $todaySummaryArticles,
            "onboardingSteps" => $onboardingSteps,
            "completedOnboarding" => $completedOnboardingCore,
            "onboardingTotal" => $onboardingTotal,
            "onboardingProgress" => $onboardingProgress,
            "onboardingNextKey" => $onboardingNextKey,
            "onboardingComplete" => $onboardingComplete,
            "onboardingAllCompleted" => $completedOnboarding,
            "playerGoals" => $playerGoals,
            "countryCurrency" => $countryCurrency,
            "localBalance" => $localBalance,
            "goldBalance" => $goldBalance,
            "myPartyMembership" => $myPartyMembership,
            "myNewspaper" => $myNewspaper,
            "pendingPartyApplications" => $pendingPartyApplications,
            "shouts" => $shouts,
            "presidentMessage" => $presidentMessage,
            "initialShoutFeed" => $initialShoutFeed,
            "shoutRestriction" => \App\Controllers\Shout::getRestrictionState($uid),
            "shoutComposerMeta" => $shoutComposerMeta,
            "isAdminUser" => $isAdmin,
            "adminCrons" => $cronEntries,
            "gameExperienceSettings" => $gameExperienceSettings,
            "quickLinks" => $quickLinks,
        ]);
    }
}

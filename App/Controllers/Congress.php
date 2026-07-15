<?php

namespace App\Controllers;

use App\Models\CandidateVote;
use App\Models\CongressCandidate;
use App\Models\CongressMember;
use App\Models\Country;
use App\Models\CountryFunds;
use App\Models\CountryRelation;
use App\Models\LawProposal;
use App\Models\LawVote;
use App\Models\PartyMember;
use App\Models\PresidentialCandidate;
use App\Models\PresidentialElectionHistory;
use App\Models\PresidentialVote;
use App\Models\User;
use App\Models\UserMoney;
use App\Models\Region;
use App\Models\RegionConnection;
use App\Models\Tax;
use App\System\App;
use App\System\AppException;
use App\System\Controller;
use App\System\Input;
use App\System\Notify;
use Illuminate\Database\Capsule\Manager as DB;

class Congress extends Controller
{
    private $ownCountry = null;
    private $electionCountry = null;

    const TYPE_ELECT_SPEAKER = 11;
    const TYPE_VOTE_OF_CONFIDENCE = 12;
    const TYPE_CUSTOMS_TARIFF = 13;
    const TYPE_EARLY_ELECTION = 14;
    const TYPE_LIFT_IMMUNITY = 15;
    const TYPE_ELECT_PRESIDENT = 16; 
    const TYPE_OMNIBUS = 17;
    const TYPE_TRADE_EMBARGO = 19;
    const TYPE_BORDER_CONTROL = 20;
    const TYPE_MOBILIZATION = 21;
    const TYPE_SHOUT_BLACKOUT = 22;
    const PRESIDENTIAL_MIN_LEVEL = 2;
    const CONGRESS_MIN_LEVEL = 2;
    const CONGRESS_SEAT_COUNT = 10;

    const PRESIDENTIAL_CANDIDACY_START = 2;
    const PRESIDENTIAL_CANDIDACY_END = 4;
    const PRESIDENTIAL_VOTING_DAY = 5;
    const PRESIDENTIAL_RESULTS_DAY = 6;

    const PARTY_CANDIDACY_START = 12;
    const PARTY_CANDIDACY_END = 14;
    const PARTY_VOTING_DAY = 15;
    const PARTY_RESULTS_DAY = 16;

    const CONGRESS_CANDIDACY_START = 21;
    const CONGRESS_CANDIDACY_END = 23;
    const CONGRESS_REVIEW_DAY = 24;
    const CONGRESS_VOTING_DAY = 25;
    const CONGRESS_RESULTS_DAY = 26;

    private function buildLawPhrase ($inputLaw) {
        $law = is_object($inputLaw) ? (method_exists($inputLaw, 'toArray') ? $inputLaw->toArray() : (array)$inputLaw) : $inputLaw;
        $type = $law["type"] ?? 0;
        $m = $law["member"] ?? 0;
        $amount = $law["amount"] ?? 0;
        $cId = $law["country"] ?? 0;
        $tCountry = $law["target_country"] ?? 0;
        $currency = $law["currency"] ?? 'local';

        switch ($type) {
            case LawProposal::TYPE_CEASE_FIRE: $phrase = "Ateşkes: " . (Country::where('id', $tCountry)->first()->name ?? 'Bilinmeyen'); break;
            case LawProposal::TYPE_MUTUAL_PROTECTION_PACT: $phrase = "MPP Anlaşması: " . (Country::where('id', $tCountry)->first()->name ?? 'Bilinmeyen'); break;
            
            // --- BÖLGE HEDEFLİ SAVAŞ İLANI MESAJI ---
            case LawProposal::TYPE_NATURAL_ENEMY: 
                $cName = Country::where('id', $tCountry)->first()->name ?? 'Bilinmeyen';
                $rName = Region::where('id', $amount)->first()->name ?? 'Bilinmeyen bölge';
                $phrase = "⚔️ Savaş İlanı: " . $cName . " (" . $rName . ")"; 
                break;
            
            case LawProposal::TYPE_MANAGER_TAX: $phrase = "Yönetici vergisini %" . $amount . " olarak belirle"; break;
            case LawProposal::TYPE_WORK_TAX: $phrase = "Çalışma vergisini %" . $amount . " olarak belirle"; break;
            case LawProposal::TYPE_TRANSFER_FUNDS: 
                if ($currency == "local") { $currency = strtoupper(Country::where('id', $cId)->first()->currency ?? ''); } 
                $uName = User::where('id', $m)->first()->nick ?? 'Bilinmeyen kullanıcı';
                $phrase = $amount . " " . $currency . " fonun " . $uName . " hesabına transferi"; break;
            case LawProposal::TYPE_IMPEACHMENT: 
                $uName = User::where('id', $m)->first()->nick ?? 'Bilinmeyen kullanıcı';
                $phrase = $uName . " isimli başkanın azledilmesi"; break;
            case 8: $phrase = "Asgari ücreti " . $amount . " birim olarak belirle"; break;
            case 9: $phrase = "🚨 OHAL İlanı (Oylamasız Geçiş)"; break;
            case self::TYPE_ELECT_SPEAKER: $phrase = "Meclis Başkanı Ataması"; break;
            case self::TYPE_ELECT_PRESIDENT: $phrase = "Ülke Başkanı Seçimi"; break;
            case self::TYPE_LIFT_IMMUNITY: $phrase = "Vekil Dokunulmazlığının Kaldırılması"; break;
            case self::TYPE_EARLY_ELECTION: $phrase = "Meclisi Feshet ve Erken Seçime Git"; break;
            case self::TYPE_VOTE_OF_CONFIDENCE: $phrase = "Kabine Güven Oylaması"; break;
            case self::TYPE_CUSTOMS_TARIFF: $phrase = "Gümrük Vergisi: %" . $amount; break;
            
            case self::TYPE_SHOUT_BLACKOUT: $phrase = "Shout Karartmasi Ilani (1 Gun / 100 Gold)"; break;
            case self::TYPE_OMNIBUS: 
                if (preg_match('/\[W:([0-9\.]+) M:([0-9\.]+) MW:([0-9\.]+)\]/', $law["reason"], $mt)) {
                    $phrase = "📦 TORBA YASA: Çalışma %{$mt[1]} | Yönetici %{$mt[2]} | Asgari {$mt[3]}";
                } else {
                    $phrase = "📦 Ekonomik Torba Yasa Paketi";
                }
                break;
            case self::TYPE_TRADE_EMBARGO: $phrase = "🚫 Ticari Ambargo: " . (Country::where('id', $tCountry)->first()->name ?? 'Bilinmeyen'); break;
            case self::TYPE_BORDER_CONTROL: $phrase = "🛂 Göç Yasağı: Ülke sınırları 3 gün kapatılsın."; break;
            case self::TYPE_MOBILIZATION: $phrase = "⚔️ Milli Seferberlik İlanı (+Hasar, +Tüketim)"; break;
            default: $phrase = "Resmi Yasa Tasarısı"; break;
        }
        return $phrase;
    }

    private function getLawMajorityRules($type, $isVetoed, $cId) {
        $country = DB::table('countries')->where('id', $cId)->first();
        $isEmergency = ($country && property_exists($country, 'emergency_session_until') && $country->emergency_session_until && strtotime($country->emergency_session_until) > time());
        
        if ($isVetoed) return 66;

        $threshold = 50; 
        if (in_array($type, [1, 2, 7, 13, 17, 19, 21])) $threshold = 60; // Nitelikli %60
        if (in_array($type, [5, 11, 14, 15, 16, 9, 20, 22])) $threshold = 66; // Anayasa %66
        
        if ($isEmergency) $threshold = max(30, $threshold - 15); // Kriz İndirimi
        return $threshold;
    }

    private function checkPendingLaws($countryId) {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('law_proposals') || !$schema->hasTable('congress_members')) {
            return;
        }
        $currentCount = CongressMember::where(["country" => $countryId])->count();
        $pendingLaws = LawProposal::where(["country" => $countryId, "finished" => 0])->get();
        if ($pendingLaws) {
            foreach($pendingLaws as $law) {
                $createdAt = strtotime($law->created_at);
                $expiresAt = $createdAt + (24 * 3600);
                $isExpired = time() >= $expiresAt;
                
                $totalVotes = $law->yes + $law->no;
                $allVoted = ($totalVotes >= $currentCount && $currentCount > 0);

                if ($law->expected_votes != $currentCount) {
                    $law->expected_votes = $currentCount;
                    $law->save();
                }

                if ($allVoted || $isExpired) {
                    $law->finished = 1;
                    if ($law->save()) {
                        $reqMaj = $this->getLawMajorityRules($law->type, $law->is_vetoed, $law->country);
                        $yesRatio = ($totalVotes > 0) ? ($law->yes / $totalVotes) * 100 : 0;
                        if ($yesRatio >= $reqMaj && $law->yes > $law->no) { 
                            try { $this->applyLaw($law->id); } catch (\Exception $e) {} 
                        }
                    }
                }
            }
        }
    }

    private function getActualPresidentId($countryId) {
        $countryObj = DB::table('countries')->where('id', $countryId)->first();
        if ($countryObj && isset($countryObj->president) && (int)$countryObj->president > 0) {
            return (int)$countryObj->president;
        }

        return 0;
    }

    private function formatRemainingSeconds($seconds)
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 60) {
            return $seconds . ' sn';
        }

        if ($seconds < 3600) {
            return floor($seconds / 60) . ' dk';
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . ' sa' . ($minutes > 0 ? ' ' . $minutes . ' dk' : '');
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        return $days . ' gun' . ($hours > 0 ? ' ' . $hours . ' sa' : '');
    }

    private function getElectionCycleKey($timestamp = null)
    {
        $timestamp = $timestamp ?: time();
        return date('Y-m-01 00:00:00', $timestamp);
    }

    private function getElectionWindowTimestamp($day, $endOfDay = false, $timestamp = null)
    {
        $timestamp = $timestamp ?: time();
        $base = date('Y-m-', $timestamp) . str_pad((string) (int) $day, 2, '0', STR_PAD_LEFT);
        return strtotime($base . ($endOfDay ? ' 23:59:59' : ' 00:00:00'));
    }

    private function getElectionPhaseMeta($type, $timestamp = null)
    {
        $timestamp = $timestamp ?: time();
        $day = (int) date('j', $timestamp);

        switch ($type) {
            case 'presidential':
                if ($day >= self::PRESIDENTIAL_CANDIDACY_START && $day <= self::PRESIDENTIAL_CANDIDACY_END) {
                    return [
                        'phase' => 'candidacy',
                        'label' => 'Adaylik',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::PRESIDENTIAL_CANDIDACY_END, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Adaylik: 2-4 / Oylama: 5 / Sonuc: 6',
                    ];
                }

                if ($day === self::PRESIDENTIAL_VOTING_DAY) {
                    return [
                        'phase' => 'voting',
                        'label' => 'Halk Oylamasi',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::PRESIDENTIAL_VOTING_DAY, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Tum vatandaslar tek oy kullanabilir.',
                    ];
                }

                if ($day === self::PRESIDENTIAL_RESULTS_DAY) {
                    return [
                        'phase' => 'results',
                        'label' => 'Sonuclar',
                        'remaining_label' => 'Bugun',
                        'schedule_note' => 'Esit oyda daha erken aday olan ustun sayilir.',
                    ];
                }
                break;

            case 'party':
                if ($day >= self::PARTY_CANDIDACY_START && $day <= self::PARTY_CANDIDACY_END) {
                    return [
                        'phase' => 'candidacy',
                        'label' => 'Adaylik',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::PARTY_CANDIDACY_END, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Parti adayligi: 12-14 / Oylama: 15 / Sonuc: 16',
                    ];
                }

                if ($day === self::PARTY_VOTING_DAY) {
                    return [
                        'phase' => 'voting',
                        'label' => 'Parti Oylamasi',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::PARTY_VOTING_DAY, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Sadece parti uyeleri oy kullanabilir.',
                    ];
                }

                if ($day === self::PARTY_RESULTS_DAY) {
                    return [
                        'phase' => 'results',
                        'label' => 'Sonuclar',
                        'remaining_label' => 'Bugun',
                        'schedule_note' => 'Esit oyda daha erken aday olan ustun sayilir.',
                    ];
                }
                break;

            case 'congress':
                if ($day >= self::CONGRESS_CANDIDACY_START && $day <= self::CONGRESS_CANDIDACY_END) {
                    return [
                        'phase' => 'candidacy',
                        'label' => 'Adaylik',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::CONGRESS_CANDIDACY_END, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Adaylik: 21-23 / Liste duzenleme: 24 / Oylama: 25 / Sonuc: 26',
                    ];
                }

                if ($day === self::CONGRESS_REVIEW_DAY) {
                    return [
                        'phase' => 'review',
                        'label' => 'Liste Duzenleme',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::CONGRESS_REVIEW_DAY, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Parti liderleri bugun kendi aday listelerini duzenleyebilir.',
                    ];
                }

                if ($day === self::CONGRESS_VOTING_DAY) {
                    return [
                        'phase' => 'voting',
                        'label' => 'Halk Oylamasi',
                        'remaining_label' => $this->formatRemainingSeconds($this->getElectionWindowTimestamp(self::CONGRESS_VOTING_DAY, true, $timestamp) - $timestamp),
                        'schedule_note' => 'Tum vatandaslar tek oy kullanir, sandalyeler parti listesi oranina gore dagitilir.',
                    ];
                }

                if ($day === self::CONGRESS_RESULTS_DAY) {
                    return [
                        'phase' => 'results',
                        'label' => 'Sonuclar',
                        'remaining_label' => 'Bugun',
                        'schedule_note' => 'Esit oyda daha erken aday olan ustun sayilir.',
                    ];
                }
                break;
        }

        return [
            'phase' => 'idle',
            'label' => 'Beklemede',
            'remaining_label' => 'Takvim disi',
            'schedule_note' => 'Aylik secim takvimi bekleniyor.',
        ];
    }

    private function getUserRow($uid)
    {
        return DB::table('users')->where('id', (int) $uid)->first();
    }

    private function getUserCitizenCountryId($uid)
    {
        $user = $this->getUserRow($uid);
        if (!$user) {
            return 0;
        }

        $countryId = (int) ($user->country_id ?? 0);
        if ($countryId > 0) {
            return $countryId;
        }

        $regionColumnsAvailable = DB::getSchemaBuilder()->hasTable('regions')
            && DB::getSchemaBuilder()->hasColumn('users', 'region')
            && DB::getSchemaBuilder()->hasColumn('regions', 'country');

        if ($regionColumnsAvailable) {
            $regionId = (int) ($user->region ?? 0);
            if ($regionId > 0) {
                return (int) (DB::table('regions')->where('id', $regionId)->value('country') ?? 0);
            }
        }

        return 0;
    }

    private function isCitizenOfCountry($uid, $countryId)
    {
        return (int) $this->getUserCitizenCountryId($uid) === (int) $countryId;
    }

    private function getPartyMembershipRow($uid)
    {
        return DB::table('party_members')->where('uid', (int) $uid)->first();
    }

    private function getPartyLeaderMembership($uid, $partyId = null)
    {
        $query = DB::table('party_members')->where('uid', (int) $uid)->where('level', PartyMember::LEVEL_LEADER);
        if ($partyId !== null) {
            $query->where('party', (int) $partyId);
        }
        return $query->first();
    }

    private function isPartyLeaderOf($uid, $partyId)
    {
        $uid = (int) $uid;
        $partyId = (int) $partyId;
        if ($uid < 1 || $partyId < 1) {
            return false;
        }

        $leaderMembership = $this->getPartyLeaderMembership($uid, $partyId);
        if ($leaderMembership) {
            return true;
        }

        return (int) (DB::table('political_parties')->where('id', $partyId)->value('uid') ?? 0) === $uid;
    }

    private function getCongressSeatCount($countryId)
    {
        if (DB::getSchemaBuilder()->hasTable('countries') && DB::getSchemaBuilder()->hasColumn('countries', 'congress_seat_count')) {
            $seatCount = (int) (DB::table('countries')->where('id', (int) $countryId)->value('congress_seat_count') ?? 0);
            if ($seatCount > 0) {
                return $seatCount;
            }
        }

        return self::CONGRESS_SEAT_COUNT;
    }

    private function getElectionMonthDisplay($timestamp = null)
    {
        $timestamp = $timestamp ?: time();
        return date('m/Y', $timestamp);
    }

    private function getElectionArchiveUrl($type)
    {
        return '/elections/archive/' . trim((string) $type);
    }

    private function getCitizenUserIdsForCountry($countryId)
    {
        $countryId = (int) $countryId;
        if ($countryId < 1 || !DB::getSchemaBuilder()->hasTable('users')) {
            return [];
        }

        $userIds = [];

        if (DB::getSchemaBuilder()->hasColumn('users', 'country_id')) {
            $userIds = array_merge(
                $userIds,
                DB::table('users')
                    ->where('country_id', $countryId)
                    ->pluck('id')
                    ->map(function ($value) {
                        return (int) $value;
                    })
                    ->toArray()
            );
        }

        $hasRegionFallback = DB::getSchemaBuilder()->hasTable('regions')
            && DB::getSchemaBuilder()->hasColumn('users', 'region')
            && DB::getSchemaBuilder()->hasColumn('regions', 'country');

        if ($hasRegionFallback) {
            $regionIds = DB::table('regions')
                ->where('country', $countryId)
                ->pluck('id')
                ->map(function ($value) {
                    return (int) $value;
                })
                ->toArray();

            if (!empty($regionIds)) {
                $fallbackQuery = DB::table('users')->whereIn('region', $regionIds);
                if (DB::getSchemaBuilder()->hasColumn('users', 'country_id')) {
                    $fallbackQuery->where(function ($query) {
                        $query->whereNull('country_id')->orWhere('country_id', 0);
                    });
                }

                $userIds = array_merge(
                    $userIds,
                    $fallbackQuery->pluck('id')
                        ->map(function ($value) {
                            return (int) $value;
                        })
                        ->toArray()
                );
            }
        }

        $userIds = array_values(array_unique(array_filter($userIds, function ($value) {
            return (int) $value > 0;
        })));

        return $userIds;
    }

    private function getPartyMemberUserIds($partyId)
    {
        $partyId = (int) $partyId;
        if ($partyId < 1 || !DB::getSchemaBuilder()->hasTable('party_members')) {
            return [];
        }

        return array_values(array_unique(DB::table('party_members')
            ->where('party', $partyId)
            ->pluck('uid')
            ->map(function ($value) {
                return (int) $value;
            })
            ->filter(function ($value) {
                return (int) $value > 0;
            })
            ->toArray()));
    }

    private function pushElectionNotifications(array $uids, $type, $title, $body, $url, array $meta = [])
    {
        foreach (array_values(array_unique(array_filter(array_map('intval', $uids)))) as $uid) {
            Notify::push($uid, $type, $title, $body, $url, $meta);
        }
    }

    private function buildCongressElectionLists($candidateRows)
    {
        $partyIds = [];
        $userIds = [];
        foreach ($candidateRows as $candidateRow) {
            $userIds[] = (int) $candidateRow->uid;
            if (!empty($candidateRow->party_id)) {
                $partyIds[] = (int) $candidateRow->party_id;
            }
        }

        $users = [];
        foreach (DB::table('users')->whereIn('id', !empty($userIds) ? array_unique($userIds) : [0])->get() as $userRow) {
            $users[(int) $userRow->id] = $userRow;
        }

        $parties = [];
        foreach (DB::table('political_parties')->whereIn('id', !empty($partyIds) ? array_unique($partyIds) : [0])->get() as $partyRow) {
            $parties[(int) $partyRow->id] = $partyRow;
        }

        $lists = [];
        foreach ($candidateRows as $candidateRow) {
            $candidateUid = (int) $candidateRow->uid;
            $candidatePartyId = (int) ($candidateRow->party_id ?? 0);
            $userRow = $users[$candidateUid] ?? null;

            $listKey = $candidatePartyId > 0
                ? 'party:' . $candidatePartyId
                : 'independent:' . $candidateUid;

            if (!isset($lists[$listKey])) {
                $lists[$listKey] = [
                    'key' => $listKey,
                    'party_id' => $candidatePartyId,
                    'label' => $candidatePartyId > 0
                        ? ($parties[$candidatePartyId]->name ?? 'Parti #' . $candidatePartyId)
                        : (($userRow->nick ?? ('Aday #' . $candidateUid)) . ' (Bagimsiz)'),
                    'total_votes' => 0,
                    'earliest_created_at' => (string) ($candidateRow->created_at ?? ''),
                    'candidates' => [],
                ];
            }

            $lists[$listKey]['total_votes'] += (int) $candidateRow->votes;
            if ($lists[$listKey]['earliest_created_at'] === '' || strcmp((string) $candidateRow->created_at, $lists[$listKey]['earliest_created_at']) < 0) {
                $lists[$listKey]['earliest_created_at'] = (string) $candidateRow->created_at;
            }

            $lists[$listKey]['candidates'][] = [
                'uid' => $candidateUid,
                'party_id' => $candidatePartyId,
                'votes' => (int) $candidateRow->votes,
                'created_at' => (string) ($candidateRow->created_at ?? ''),
            ];
        }

        foreach ($lists as &$list) {
            usort($list['candidates'], function ($a, $b) {
                if ((int) $a['votes'] === (int) $b['votes']) {
                    return strcmp((string) $a['created_at'], (string) $b['created_at']);
                }
                return (int) $b['votes'] <=> (int) $a['votes'];
            });
        }
        unset($list);

        return $lists;
    }

    private function allocateCongressSeatsFromLists(array $lists, $seatCount)
    {
        $seatCount = max(0, (int) $seatCount);
        $allocatedSeats = [];

        for ($i = 0; $i < $seatCount; $i++) {
            $bestKey = null;
            $bestQuotient = -1;
            $bestVotes = -1;
            $bestCreatedAt = null;

            foreach ($lists as $listKey => $list) {
                $filledSeats = (int) ($allocatedSeats[$listKey] ?? 0);
                if ($filledSeats >= count($list['candidates'])) {
                    continue;
                }

                $quotient = ((int) $list['total_votes']) / ($filledSeats + 1);
                $tieVotes = (int) $list['total_votes'];
                $tieCreatedAt = (string) ($list['earliest_created_at'] ?? '');

                $isBetter = $bestKey === null
                    || $quotient > $bestQuotient
                    || ($quotient == $bestQuotient && $tieVotes > $bestVotes)
                    || ($quotient == $bestQuotient && $tieVotes === $bestVotes && strcmp($tieCreatedAt, (string) $bestCreatedAt) < 0);

                if ($isBetter) {
                    $bestKey = $listKey;
                    $bestQuotient = $quotient;
                    $bestVotes = $tieVotes;
                    $bestCreatedAt = $tieCreatedAt;
                }
            }

            if ($bestKey === null) {
                break;
            }

            $allocatedSeats[$bestKey] = (int) ($allocatedSeats[$bestKey] ?? 0) + 1;
        }

        return $allocatedSeats;
    }

    private function buildElectionResultNotice($history, $label, $monthLabel)
    {
        if (empty($history) || !is_array($history)) {
            return null;
        }

        $latest = $history[0] ?? null;
        if (!$latest) {
            return null;
        }

        $winnerNick = trim((string) ($latest['winner_nick'] ?? ''));
        $winnerVotes = (int) ($latest['winner_votes'] ?? 0);
        $totalVotes = (int) ($latest['total_votes'] ?? 0);
        $finishedAt = (string) ($latest['finished_at'] ?? '');

        return [
            'title' => $label . ' Sonucu',
            'month_label' => $monthLabel,
            'winner_nick' => $winnerNick !== '' ? $winnerNick : 'Sonuc cikmadi',
            'winner_votes' => $winnerVotes,
            'total_votes' => $totalVotes,
            'finished_at' => $finishedAt,
            'summary' => $winnerVotes > 0
                ? ($winnerNick !== '' ? ($winnerNick . ' ' . $winnerVotes . ' oyla turu kapatti.') : ($winnerVotes . ' oyla sonuc kapanisi yapildi.'))
                : 'Bu turda kazanan cikmadi veya oy kullanilmadi.',
        ];
    }

    private function buildFeaturedElectionPoster($candidate, $contextTitle, $fallbackBody = '')
    {
        if (!$candidate || !is_array($candidate)) {
            return null;
        }

        $headline = trim((string) ($candidate['campaign_title'] ?? ''));
        $body = trim((string) ($candidate['campaign_message'] ?? ''));

        if ($headline === '') {
            $headline = $candidate['nick'] . ' icin ' . $contextTitle;
        }

        if ($body === '') {
            if (!empty($candidate['party_name'])) {
                $body = $candidate['party_name'] . ' destegiyle secim hattinda yer aliyor.';
            } elseif ($fallbackBody !== '') {
                $body = $fallbackBody;
            } else {
                $body = 'Secim hattinda one cikan aday karti.';
            }
        }

        return [
            'uid' => (int) ($candidate['uid'] ?? 0),
            'nick' => (string) ($candidate['nick'] ?? 'Aday'),
            'avatar' => $candidate['avatar'] ?? null,
            'headline' => $headline,
            'body' => $body,
            'votes' => (int) ($candidate['votes'] ?? 0),
            'vote_percentage' => (int) ($candidate['vote_percentage'] ?? 0),
            'party_name' => (string) ($candidate['party_name'] ?? ''),
        ];
    }

    private function finalizeExpiredCandidacies($countryId = null)
    {
        $deadline = date('Y-m-d H:i:s', time() - CongressCandidate::VOTE_DATE_LIMIT);
        $query = CongressCandidate::where('created_at', '<=', $deadline);

        if ($countryId !== null) {
            $query->where('country', (int) $countryId);
        }

        $expiredCandidates = $query->get();
        if (!$expiredCandidates || $expiredCandidates->count() === 0) {
            return;
        }

        foreach ($expiredCandidates as $candidate) {
            if ((int) $candidate->yes > (int) $candidate->no) {
                $partyMembership = PartyMember::where('uid', (int) $candidate->uid)->first();
                $partyId = (int) ($partyMembership->party ?? 0);

                $existingMember = CongressMember::where('uid', (int) $candidate->uid)->first();
                if ($existingMember) {
                    $existingMember->party = $partyId;
                    $existingMember->country = (int) $candidate->country;
                    $existingMember->save();
                } else {
                    CongressMember::create([
                        'party' => $partyId,
                        'uid' => (int) $candidate->uid,
                        'country' => (int) $candidate->country,
                    ]);
                }
            }

            CandidateVote::where('candidate', (int) $candidate->uid)->delete();
            $candidate->delete();
        }
    }

    private function buildElectionData($countryId, $uid)
    {
        $countryId = (int) $countryId;
        $uid = (int) $uid;
        $phaseMeta = $this->getElectionPhaseMeta('congress');
        $history = $this->getCongressElectionHistory($countryId);
        $requirements = $this->getCongressCandidateRequirements($countryId, $uid);

        if ($countryId < 1 || !DB::getSchemaBuilder()->hasTable('congress_elections') || !DB::getSchemaBuilder()->hasTable('congress_election_candidates') || !DB::getSchemaBuilder()->hasTable('congress_election_votes')) {
            return [
                'available' => false,
                'phase' => 'idle',
                'phase_label' => 'Kapali',
                'phase_note' => 'Kongre secimi sistemi hazir degil.',
                'schedule_note' => 'Adaylik: 21-23 / Liste duzenleme: 24 / Oylama: 25 / Sonuc: 26',
                'requirements' => $requirements['items'],
                'requirements_ok' => false,
                'candidates' => [],
                'history' => $history,
                'my_candidate' => null,
                'my_vote_candidate_uid' => 0,
                'can_apply' => false,
                'can_vote' => false,
                'can_review' => false,
                'remaining_label' => 'Kapali',
                'seat_count' => $this->getCongressSeatCount($countryId),
                'month_label' => $this->getElectionMonthDisplay(),
                'tie_rule' => 'Esit oyda daha erken aday olan kazanir.',
                'active_count' => 0,
                'total_votes' => 0,
                'is_congress_member' => CongressMember::where(['country' => $countryId, 'uid' => $uid])->exists(),
            ];
        }

        $election = $this->ensureCongressElectionRow($countryId);
        $candidateRows = [];
        $myVoteCandidateUid = 0;
        $totalVotes = 0;
        $myCandidate = null;
        $isCongressMember = CongressMember::where(['country' => $countryId, 'uid' => $uid])->exists();
        $partyLeaderMembership = $this->getPartyLeaderMembership($uid);
        $currentCongressMemberIds = CongressMember::where('country', $countryId)->pluck('uid')->map(function ($value) {
            return (int) $value;
        })->toArray();
        $recentWinnerIds = [];
        if (!empty($history) && !empty($history[0]['winners']) && is_array($history[0]['winners'])) {
            $recentWinnerIds = array_map(function ($row) {
                return (int) ($row['uid'] ?? 0);
            }, $history[0]['winners']);
        }

        if ($election) {
            $candidateRows = DB::table('congress_election_candidates')
                ->where('election_id', (int) $election->id)
                ->orderBy('votes', 'DESC')
                ->orderBy('created_at', 'ASC')
                ->get();

            $myVoteCandidateUid = (int) (DB::table('congress_election_votes')
                ->where('election_id', (int) $election->id)
                ->where('voter_uid', $uid)
                ->value('candidate_uid') ?? 0);
        }

        $candidateIds = [];
        $partyIds = [];
        foreach ($candidateRows as $candidateRow) {
            $candidateIds[] = (int) $candidateRow->uid;
            $totalVotes += (int) $candidateRow->votes;
            if (!empty($candidateRow->party_id)) {
                $partyIds[] = (int) $candidateRow->party_id;
            }
        }

        $users = [];
        foreach (DB::table('users')->whereIn('id', !empty($candidateIds) ? $candidateIds : [0])->get() as $userRow) {
            $users[(int) $userRow->id] = $userRow;
        }

        $parties = [];
        foreach (DB::table('political_parties')->whereIn('id', !empty($partyIds) ? array_unique($partyIds) : [0])->get() as $partyRow) {
            $parties[(int) $partyRow->id] = $partyRow;
        }

        $candidates = [];
        foreach ($candidateRows as $candidateRow) {
            $candidateUid = (int) $candidateRow->uid;
            $userRow = $users[$candidateUid] ?? null;
            if (!$userRow) {
                continue;
            }

            $candidatePartyId = (int) ($candidateRow->party_id ?? 0);
            $candidateData = [
                'uid' => $candidateUid,
                'nick' => $userRow->nick,
                'avatar' => $userRow->avatar ?? null,
                'party_id' => $candidatePartyId,
                'party_name' => $parties[$candidatePartyId]->name ?? 'Bagimsiz',
                'votes' => (int) $candidateRow->votes,
                'vote_percentage' => $totalVotes > 0 ? (int) round(((int) $candidateRow->votes / $totalVotes) * 100) : 0,
                'is_me' => $candidateUid === $uid,
                'has_voted' => $myVoteCandidateUid === $candidateUid,
                'can_vote' => $phaseMeta['phase'] === 'voting' && $myVoteCandidateUid === 0 && $this->isCitizenOfCountry($uid, $countryId),
                'can_remove' => $phaseMeta['phase'] === 'review' && $partyLeaderMembership && (int) $partyLeaderMembership->party === $candidatePartyId,
                'is_current_officeholder' => in_array($candidateUid, $currentCongressMemberIds, true),
                'is_recent_winner' => in_array($candidateUid, $recentWinnerIds, true),
            ];

            if ($candidateUid === $uid) {
                $myCandidate = $candidateData;
            }

            $candidates[] = $candidateData;
        }

        return [
            'available' => true,
            'phase' => $phaseMeta['phase'],
            'phase_label' => $phaseMeta['label'],
            'phase_note' => $phaseMeta['schedule_note'],
            'schedule_note' => 'Adaylik: 21-23 / Liste duzenleme: 24 / Oylama: 25 / Sonuc: 26',
            'requirements' => $requirements['items'],
            'requirements_ok' => $requirements['eligible'],
            'candidates' => $candidates,
            'history' => $history,
            'my_candidate' => $myCandidate,
            'my_vote_candidate_uid' => $myVoteCandidateUid,
            'can_apply' => $phaseMeta['phase'] === 'candidacy' && !$isCongressMember && $myCandidate === null && $requirements['eligible'],
            'can_vote' => $phaseMeta['phase'] === 'voting' && $myVoteCandidateUid === 0 && $this->isCitizenOfCountry($uid, $countryId),
            'can_review' => $phaseMeta['phase'] === 'review' && $partyLeaderMembership && $this->isCitizenOfCountry($uid, $countryId),
            'remaining_label' => $phaseMeta['remaining_label'],
            'seat_count' => $this->getCongressSeatCount($countryId),
            'month_label' => $this->getElectionMonthDisplay(),
            'tie_rule' => 'Sandalye dagitimi D\'Hondt ile, parti ici esitlikte daha erken adaylik ustun sayilir.',
            'active_count' => count($candidates),
            'total_votes' => $totalVotes,
            'is_congress_member' => $isCongressMember,
            'result_notice' => $this->buildElectionResultNotice($history, 'Kongre Secimi', $this->getElectionMonthDisplay()),
            'featured_candidate' => $this->buildFeaturedElectionPoster($candidates[0] ?? null, 'Kongre Secimi', 'Ulusal meclis hattinda one cikan aday.'),
        ];
    }

    private function getPresidentialElectionKey($countryId)
    {
        return $this->getElectionCycleKey();
    }

    private function getPresidentialElectionPhase($electionKey = null)
    {
        return $this->getElectionPhaseMeta('presidential')['phase'];
    }

    private function getPresidentialCandidateRequirements($countryId, $uid)
    {
        $user = $this->getUserRow($uid);
        $citizenCountryId = $this->getUserCitizenCountryId($uid);

        $requirements = [
            [
                'label' => 'Vatandaslik',
                'ok' => $citizenCountryId === (int) $countryId,
                'hint' => 'Secime sadece kendi ulkenin vatandasi aday olabilir.',
            ],
            [
                'label' => 'Seviye ' . self::PRESIDENTIAL_MIN_LEVEL . '+',
                'ok' => (int) ($user->level ?? 0) >= self::PRESIDENTIAL_MIN_LEVEL,
                'hint' => 'Baskanlik adayligi icin minimum oyuncu seviyesi gerekir.',
            ],
        ];

        $eligible = true;
        foreach ($requirements as $requirement) {
            if (!$requirement['ok']) {
                $eligible = false;
                break;
            }
        }

        return [
            'eligible' => $eligible,
            'items' => $requirements,
        ];
    }

    private function getPresidentialElectionHistory($countryId, $limit = 5)
    {
        if (!DB::getSchemaBuilder()->hasTable('presidential_election_histories')) {
            return [];
        }

        $rows = PresidentialElectionHistory::where('country', (int) $countryId)
            ->orderBy('finished_at', 'DESC')
            ->limit(max(1, (int) $limit))
            ->get();

        $history = [];
        foreach ($rows as $row) {
            $winnerUser = DB::table('users')->where('id', (int) $row->winner_uid)->first();
            $summary = [];
            if (!empty($row->summary_json)) {
                $decoded = json_decode($row->summary_json, true);
                if (is_array($decoded)) {
                    $summary = $decoded;
                }
            }

            $history[] = [
                'winner_uid' => (int) $row->winner_uid,
                'winner_nick' => $row->winner_uid > 0 ? ($winnerUser->nick ?? 'Bilinmeyen') : 'Esitlik / Sonuc cikmadi',
                'winner_votes' => (int) $row->winner_votes,
                'total_votes' => (int) $row->total_votes,
                'candidate_count' => (int) $row->candidate_count,
                'finished_at' => $row->finished_at,
                'summary' => $summary,
            ];
        }

        return $history;
    }

    private function finalizePresidentialCycle($countryId, $electionKey)
    {
        $candidateRows = PresidentialCandidate::where('country', (int) $countryId)
            ->where('election_key', $electionKey)
            ->orderBy('votes', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->get();

        if ($candidateRows->count() === 0) {
            return false;
        }

        $summary = [];
        $totalVotes = 0;
        foreach ($candidateRows as $candidateRow) {
            $userRow = DB::table('users')->where('id', (int) $candidateRow->uid)->first();
            $summary[] = [
                'uid' => (int) $candidateRow->uid,
                'nick' => $userRow->nick ?? ('ID ' . (int) $candidateRow->uid),
                'votes' => (int) $candidateRow->votes,
                'campaign_title' => (string) ($candidateRow->campaign_title ?? ''),
            ];
            $totalVotes += (int) $candidateRow->votes;
        }

        $winner = $candidateRows->first();
        if ($winner) {
            DB::table('countries')->where('id', (int) $countryId)->update([
                'president' => (int) $winner->uid,
            ]);
        }

        if (DB::getSchemaBuilder()->hasTable('presidential_election_histories')) {
            PresidentialElectionHistory::updateOrCreate(
                [
                    'country' => (int) $countryId,
                    'election_key' => $electionKey,
                ],
                [
                    'winner_uid' => (int) ($winner->uid ?? 0),
                    'winner_votes' => (int) ($winner->votes ?? 0),
                    'total_votes' => $totalVotes,
                    'candidate_count' => $candidateRows->count(),
                    'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        $winnerUid = (int) ($winner->uid ?? 0);
        $winnerNick = trim((string) ($summary[0]['nick'] ?? ''));
        $winnerVotes = (int) ($winner->votes ?? 0);
        $countryName = (string) (DB::table('countries')->where('id', (int) $countryId)->value('name') ?? 'Ulke');
        $archiveUrl = $this->getElectionArchiveUrl('presidential');

        if ($winnerUid > 0) {
            Notify::push(
                $winnerUid,
                'election',
                'Cumhurbaskanligini kazandin',
                $countryName . ' icin ' . $winnerVotes . ' oyla secildin.',
                $archiveUrl,
                ['scope' => 'presidential', 'country_id' => (int) $countryId]
            );
        }

        $citizenRecipients = array_diff($this->getCitizenUserIdsForCountry($countryId), [$winnerUid]);
        $broadcastBody = $winnerUid > 0
            ? ($winnerNick . ' ' . $winnerVotes . ' oyla cumhurbaskani secildi.')
            : 'Bu turda cumhurbaskani secimi sonuclandi ancak kazanan cikmadi.';
        $this->pushElectionNotifications(
            $citizenRecipients,
            'election',
            'Cumhurbaskanligi secimi sonuclandi',
            $broadcastBody,
            $archiveUrl,
            ['scope' => 'presidential', 'country_id' => (int) $countryId]
        );

        PresidentialVote::where('country', (int) $countryId)->where('election_key', $electionKey)->delete();
        PresidentialCandidate::where('country', (int) $countryId)->where('election_key', $electionKey)->delete();

        return true;
    }

    private function finalizeExpiredPresidentialElection($countryId = null)
    {
        if (!DB::getSchemaBuilder()->hasTable('presidential_candidates') || !DB::getSchemaBuilder()->hasTable('presidential_votes')) {
            return 0;
        }

        $query = PresidentialCandidate::query()->select('country', 'election_key')->groupBy('country', 'election_key');
        if ($countryId !== null) {
            $query->where('country', (int) $countryId);
        }

        $finalized = 0;
        foreach ($query->get() as $cycle) {
            $resultTimestamp = $this->getElectionWindowTimestamp(self::PRESIDENTIAL_RESULTS_DAY, false, strtotime($cycle->election_key));
            if (time() < $resultTimestamp) {
                continue;
            }

            if (DB::getSchemaBuilder()->hasTable('presidential_election_histories')) {
                $exists = PresidentialElectionHistory::where('country', (int) $cycle->country)
                    ->where('election_key', $cycle->election_key)
                    ->exists();
                if ($exists) {
                    PresidentialVote::where('country', (int) $cycle->country)->where('election_key', $cycle->election_key)->delete();
                    PresidentialCandidate::where('country', (int) $cycle->country)->where('election_key', $cycle->election_key)->delete();
                    continue;
                }
            }

            if ($this->finalizePresidentialCycle((int) $cycle->country, $cycle->election_key)) {
                $finalized++;
            }
        }

        return $finalized;
    }

    private function buildPresidentialElectionData($countryId, $uid)
    {
        $countryId = (int) $countryId;
        $uid = (int) $uid;

        $phaseMeta = $this->getElectionPhaseMeta('presidential');
        $history = $this->getPresidentialElectionHistory($countryId);

        if ($countryId < 1 || !DB::getSchemaBuilder()->hasTable('presidential_candidates') || !DB::getSchemaBuilder()->hasTable('presidential_votes')) {
            return [
                'available' => false,
                'phase' => 'idle',
                'phase_label' => 'Kapali',
                'phase_note' => 'Baskanlik secimi sistemi hazir degil.',
                'schedule_note' => 'Adaylik: 2-4 / Oylama: 5 / Sonuc: 6',
                'can_apply' => false,
                'can_vote' => false,
                'my_candidate' => null,
                'voted_candidate_uid' => 0,
                'candidates' => [],
                'total_votes' => 0,
                'remaining_label' => 'Kapali',
                'requirements' => [],
                'requirements_ok' => false,
                'history' => $history,
                'month_label' => $this->getElectionMonthDisplay(),
                'tie_rule' => 'Esit oyda daha erken aday olan kazanir.',
            ];
        }

        $electionKey = $this->getElectionCycleKey();
        $candidateRows = PresidentialCandidate::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->orderBy('votes', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->get();

        $voteRow = PresidentialVote::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->where('uid', $uid)
            ->first();

        $votedCandidateUid = (int) ($voteRow->candidate_uid ?? 0);
        $totalVotes = 0;
        $candidateIds = [];
        foreach ($candidateRows as $candidateRow) {
            $candidateIds[] = (int) $candidateRow->uid;
            $totalVotes += (int) $candidateRow->votes;
        }

        $users = [];
        $partyMemberships = [];
        $parties = [];
        if (!empty($candidateIds)) {
            foreach (DB::table('users')->whereIn('id', $candidateIds)->get() as $userRow) {
                $users[(int) $userRow->id] = $userRow;
            }

            foreach (DB::table('party_members')->whereIn('uid', $candidateIds)->get() as $membershipRow) {
                $partyMemberships[(int) $membershipRow->uid] = $membershipRow;
            }

            $partyIds = [];
            foreach ($partyMemberships as $membershipRow) {
                if (!empty($membershipRow->party)) {
                    $partyIds[] = (int) $membershipRow->party;
                }
            }

            if (!empty($partyIds)) {
                foreach (DB::table('political_parties')->whereIn('id', array_unique($partyIds))->get() as $partyRow) {
                    $parties[(int) $partyRow->id] = $partyRow;
                }
            }
        }

        $requirements = $this->getPresidentialCandidateRequirements($countryId, $uid);
        $candidates = [];
        $myCandidate = null;
        $currentPresidentUid = (int) $this->getActualPresidentId($countryId);
        $recentWinnerUid = (int) (($history[0]['winner_uid'] ?? 0));
        foreach ($candidateRows as $candidateRow) {
            $candidateUid = (int) $candidateRow->uid;
            $userRow = $users[$candidateUid] ?? null;
            if (!$userRow) {
                continue;
            }

            $partyMembership = $partyMemberships[$candidateUid] ?? null;
            $partyRow = ($partyMembership && !empty($partyMembership->party))
                ? ($parties[(int) $partyMembership->party] ?? null)
                : null;

            $candidateData = [
                'uid' => $candidateUid,
                'nick' => $userRow->nick,
                'avatar' => $userRow->avatar ?? null,
                'party_name' => $partyRow->name ?? 'Bagimsiz',
                'campaign_title' => trim((string) ($candidateRow->campaign_title ?? '')),
                'campaign_message' => trim((string) ($candidateRow->campaign_message ?? '')),
                'votes' => (int) $candidateRow->votes,
                'vote_percentage' => $totalVotes > 0 ? (int) round(((int) $candidateRow->votes / $totalVotes) * 100) : 0,
                'is_me' => $candidateUid === $uid,
                'has_voted' => $votedCandidateUid === $candidateUid,
                'can_vote' => $phaseMeta['phase'] === 'voting' && $votedCandidateUid === 0 && $this->isCitizenOfCountry($uid, $countryId),
                'is_current_officeholder' => $candidateUid === $currentPresidentUid,
                'is_recent_winner' => $recentWinnerUid > 0 && $candidateUid === $recentWinnerUid,
            ];

            if ($candidateUid === $uid) {
                $myCandidate = $candidateData;
            }

            $candidates[] = $candidateData;
        }

        return [
            'available' => true,
            'phase' => $phaseMeta['phase'],
            'phase_label' => $phaseMeta['label'],
            'phase_note' => $phaseMeta['schedule_note'],
            'schedule_note' => 'Adaylik: 2-4 / Oylama: 5 / Sonuc: 6',
            'can_apply' => $phaseMeta['phase'] === 'candidacy' && $myCandidate === null && $requirements['eligible'],
            'can_vote' => $phaseMeta['phase'] === 'voting' && $votedCandidateUid === 0 && $this->isCitizenOfCountry($uid, $countryId),
            'my_candidate' => $myCandidate,
            'voted_candidate_uid' => $votedCandidateUid,
            'candidates' => $candidates,
            'total_votes' => $totalVotes,
            'remaining_label' => $phaseMeta['remaining_label'],
            'requirements' => $requirements['items'],
            'requirements_ok' => $requirements['eligible'],
            'history' => $history,
            'month_label' => $this->getElectionMonthDisplay(),
            'tie_rule' => 'Esit oyda daha erken aday olan kazanir.',
            'result_notice' => $this->buildElectionResultNotice($history, 'Cumhurbaskanligi', $this->getElectionMonthDisplay()),
            'featured_candidate' => $this->buildFeaturedElectionPoster($candidates[0] ?? null, 'Cumhurbaskanligi', 'Halk oylamasi icin one cikan aday.'),
        ];
    }

    private function getPartyElectionHistory($partyId, $limit = 5)
    {
        if (!DB::getSchemaBuilder()->hasTable('party_election_histories')) {
            return [];
        }

        return DB::table('party_election_histories')
            ->where('party_id', (int) $partyId)
            ->orderBy('finished_at', 'DESC')
            ->limit(max(1, (int) $limit))
            ->get()
            ->map(function ($row) {
                $winnerUser = DB::table('users')->where('id', (int) ($row->winner_uid ?? 0))->first();
                return [
                    'winner_uid' => (int) ($row->winner_uid ?? 0),
                    'winner_nick' => $winnerUser->nick ?? 'Bilinmeyen',
                    'winner_votes' => (int) ($row->winner_votes ?? 0),
                    'total_votes' => (int) ($row->total_votes ?? 0),
                    'candidate_count' => (int) ($row->candidate_count ?? 0),
                    'finished_at' => $row->finished_at,
                ];
            })
            ->toArray();
    }

    private function ensurePartyElectionRow($partyId)
    {
        if (!DB::getSchemaBuilder()->hasTable('party_elections')) {
            return null;
        }

        $phaseMeta = $this->getElectionPhaseMeta('party');
        if (!in_array($phaseMeta['phase'], ['candidacy', 'voting', 'results'], true)) {
            return null;
        }

        $electionKey = $this->getElectionCycleKey();
        $row = DB::table('party_elections')
            ->where('party_id', (int) $partyId)
            ->where('election_key', $electionKey)
            ->first();

        if ($row) {
            return $row;
        }

        if ($phaseMeta['phase'] === 'results') {
            return null;
        }

        $id = DB::table('party_elections')->insertGetId([
            'party_id' => (int) $partyId,
            'election_key' => $electionKey,
            // The database stores lifecycle state; the visible phase is calculated from the election calendar.
            'status' => 'running',
            'created_at' => date('Y-m-d H:i:s'),
            'ends_at' => date('Y-m-d H:i:s', $this->getElectionWindowTimestamp(self::PARTY_RESULTS_DAY, true)),
        ]);

        return DB::table('party_elections')->where('id', $id)->first();
    }

    private function finalizePartyElectionRow($election)
    {
        $electionId = (int) $election->id;
        $partyId = (int) $election->party_id;
        $candidateRows = DB::table('party_candidates')
            ->where('election_id', $electionId)
            ->orderBy('created_at', 'ASC')
            ->get();

        $voteCounts = [];
        $totalVotes = 0;
        if ($candidateRows->count() > 0) {
            $counts = DB::table('party_votes')
                ->select('candidate_uid', DB::raw('COUNT(*) as total'))
                ->where('election_id', $electionId)
                ->groupBy('candidate_uid')
                ->get();

            foreach ($counts as $countRow) {
                $voteCounts[(int) $countRow->candidate_uid] = (int) $countRow->total;
                $totalVotes += (int) $countRow->total;
            }
        }

        $ordered = [];
        foreach ($candidateRows as $candidateRow) {
            $ordered[] = [
                'uid' => (int) $candidateRow->uid,
                'votes' => (int) ($voteCounts[(int) $candidateRow->uid] ?? 0),
                'created_at' => $candidateRow->created_at,
            ];
        }

        usort($ordered, function ($a, $b) {
            if ($a['votes'] === $b['votes']) {
                return strcmp((string) $a['created_at'], (string) $b['created_at']);
            }
            return $b['votes'] <=> $a['votes'];
        });

        $winnerUid = (int) ($ordered[0]['uid'] ?? 0);
        $winnerVotes = (int) ($ordered[0]['votes'] ?? 0);

        if ($winnerUid > 0) {
            $oldLeaderUid = (int) (DB::table('political_parties')->where('id', $partyId)->value('uid') ?? 0);
            DB::table('political_parties')->where('id', $partyId)->update(['uid' => $winnerUid]);

            if ($oldLeaderUid > 0 && $oldLeaderUid !== $winnerUid) {
                DB::table('party_members')
                    ->where('party', $partyId)
                    ->where('uid', $oldLeaderUid)
                    ->update(['level' => PartyMember::LEVEL_AFFILIATED]);
            }

            $winnerMembership = DB::table('party_members')
                ->where('party', $partyId)
                ->where('uid', $winnerUid)
                ->first();

            if ($winnerMembership) {
                DB::table('party_members')
                    ->where('party', $partyId)
                    ->where('uid', $winnerUid)
                    ->update(['level' => PartyMember::LEVEL_LEADER]);
            } else {
                DB::table('party_members')->insert([
                    'party' => $partyId,
                    'uid' => $winnerUid,
                    'level' => PartyMember::LEVEL_LEADER,
                ]);
            }
        }

        if (DB::getSchemaBuilder()->hasTable('party_election_histories')) {
            DB::table('party_election_histories')->updateOrInsert(
                ['party_id' => $partyId, 'election_key' => $election->election_key],
                [
                    'winner_uid' => $winnerUid,
                    'winner_votes' => $winnerVotes,
                    'total_votes' => $totalVotes,
                    'candidate_count' => count($ordered),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        $partyName = (string) (DB::table('political_parties')->where('id', $partyId)->value('name') ?? 'Parti');
        $archiveUrl = $this->getElectionArchiveUrl('party');

        if ($winnerUid > 0) {
            Notify::push(
                $winnerUid,
                'election',
                'Parti liderligini kazandin',
                $partyName . ' icin ' . $winnerVotes . ' oyla lider secildin.',
                $archiveUrl,
                ['scope' => 'party', 'party_id' => $partyId]
            );
        }

        $partyRecipients = array_diff($this->getPartyMemberUserIds($partyId), [$winnerUid]);
        $winnerName = (string) (DB::table('users')->where('id', $winnerUid)->value('nick') ?? 'Bilinmeyen');
        $this->pushElectionNotifications(
            $partyRecipients,
            'election',
            'Parti liderligi secimi sonuclandi',
            $winnerUid > 0
                ? ($winnerName . ' ' . $winnerVotes . ' oyla parti lideri oldu.')
                : 'Bu turda parti liderligi seciminde kazanan cikmadi.',
            $archiveUrl,
            ['scope' => 'party', 'party_id' => $partyId]
        );

        DB::table('party_elections')->where('id', $electionId)->update([
            'status' => 'finished',
            'finished_at' => date('Y-m-d H:i:s'),
            'ends_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    private function finalizeExpiredPartyElections($partyId = null)
    {
        if (!DB::getSchemaBuilder()->hasTable('party_elections')) {
            return 0;
        }

        $query = DB::table('party_elections')->whereNotNull('election_key');
        if ($partyId !== null) {
            $query->where('party_id', (int) $partyId);
        }

        $finalized = 0;
        foreach ($query->get() as $election) {
            $resultTimestamp = $this->getElectionWindowTimestamp(self::PARTY_RESULTS_DAY, false, strtotime($election->election_key));
            if (time() < $resultTimestamp) {
                continue;
            }
            if (($election->status ?? '') === 'finished') {
                continue;
            }
            if ($this->finalizePartyElectionRow($election)) {
                $finalized++;
            }
        }

        return $finalized;
    }

    private function buildPartyElectionData($uid)
    {
        $membership = $this->getPartyMembershipRow($uid);
        if (!$membership || empty($membership->party)) {
            return [
                'available' => false,
                'phase' => 'idle',
                'phase_label' => 'Parti Uyelik Gerekli',
                'phase_note' => 'Parti liderligi secimi icin aktif bir parti uyeligin olmali.',
                'schedule_note' => 'Adaylik: 12-14 / Oylama: 15 / Sonuc: 16',
                'requirements' => [],
                'requirements_ok' => false,
                'candidates' => [],
                'history' => [],
                'my_candidate' => null,
                'my_vote_candidate_uid' => 0,
                'can_apply' => false,
                'can_vote' => false,
                'remaining_label' => 'Uyelik yok',
                'party_name' => null,
                'month_label' => $this->getElectionMonthDisplay(),
                'tie_rule' => 'Esit oyda daha erken aday olan kazanir.',
            ];
        }

        $partyId = (int) $membership->party;
        $partyName = (string) (DB::table('political_parties')->where('id', $partyId)->value('name') ?? 'Parti');
        $phaseMeta = $this->getElectionPhaseMeta('party');
        $election = $this->ensurePartyElectionRow($partyId);
        $history = $this->getPartyElectionHistory($partyId);
        $requirements = [
            ['label' => 'Aktif Parti Uyelik', 'ok' => true, 'hint' => 'Sadece ayni partinin aktif uyeleri aday olabilir.'],
        ];

        $candidates = [];
        $myCandidate = null;
        $myVoteCandidateUid = 0;
        $totalVotes = 0;
        $currentLeaderUid = (int) (DB::table('political_parties')->where('id', $partyId)->value('uid') ?? 0);
        $recentWinnerUid = (int) (($history[0]['winner_uid'] ?? 0));

        if ($election) {
            $candidateRows = DB::table('party_candidates')
                ->where('election_id', (int) $election->id)
                ->orderBy('created_at', 'ASC')
                ->get();

            $myVoteCandidateUid = (int) (DB::table('party_votes')
                ->where('election_id', (int) $election->id)
                ->where('voter_uid', (int) $uid)
                ->value('candidate_uid') ?? 0);

            $voteCounts = [];
            foreach (DB::table('party_votes')
                ->select('candidate_uid', DB::raw('COUNT(*) as total'))
                ->where('election_id', (int) $election->id)
                ->groupBy('candidate_uid')
                ->get() as $countRow) {
                $voteCounts[(int) $countRow->candidate_uid] = (int) $countRow->total;
                $totalVotes += (int) $countRow->total;
            }

            foreach ($candidateRows as $candidateRow) {
                $userRow = $this->getUserRow((int) $candidateRow->uid);
                if (!$userRow) {
                    continue;
                }

                $candidateData = [
                    'uid' => (int) $candidateRow->uid,
                    'nick' => $userRow->nick,
                    'avatar' => $userRow->avatar ?? null,
                    'votes' => (int) ($voteCounts[(int) $candidateRow->uid] ?? 0),
                    'vote_percentage' => $totalVotes > 0 ? (int) round((((int) ($voteCounts[(int) $candidateRow->uid] ?? 0)) / $totalVotes) * 100) : 0,
                    'is_me' => (int) $candidateRow->uid === (int) $uid,
                    'has_voted' => $myVoteCandidateUid === (int) $candidateRow->uid,
                    'party_name' => $partyName,
                    'is_current_officeholder' => (int) $candidateRow->uid === $currentLeaderUid,
                    'is_recent_winner' => $recentWinnerUid > 0 && (int) $candidateRow->uid === $recentWinnerUid,
                ];

                if ($candidateData['is_me']) {
                    $myCandidate = $candidateData;
                }

                $candidates[] = $candidateData;
            }
        }

        return [
            'available' => true,
            'phase' => $phaseMeta['phase'],
            'phase_label' => $phaseMeta['label'],
            'phase_note' => $phaseMeta['schedule_note'],
            'schedule_note' => 'Adaylik: 12-14 / Oylama: 15 / Sonuc: 16',
            'requirements' => $requirements,
            'requirements_ok' => true,
            'candidates' => $candidates,
            'history' => $history,
            'my_candidate' => $myCandidate,
            'my_vote_candidate_uid' => $myVoteCandidateUid,
            'can_apply' => $phaseMeta['phase'] === 'candidacy' && $myCandidate === null,
            'can_vote' => $phaseMeta['phase'] === 'voting' && $myVoteCandidateUid === 0,
            'remaining_label' => $phaseMeta['remaining_label'],
            'party_name' => $partyName,
            'party_id' => $partyId,
            'month_label' => $this->getElectionMonthDisplay(),
            'tie_rule' => 'Esit oyda daha erken aday olan kazanir.',
            'total_votes' => $totalVotes,
            'result_notice' => $this->buildElectionResultNotice($history, 'Parti Liderligi', $this->getElectionMonthDisplay()),
            'featured_candidate' => $this->buildFeaturedElectionPoster($candidates[0] ?? null, 'Parti Liderligi', 'Parti uyeleri tarafindan belirlenen one cikan aday.'),
        ];
    }

    private function getCongressElectionHistory($countryId, $limit = 5)
    {
        if (!DB::getSchemaBuilder()->hasTable('congress_election_histories')) {
            return [];
        }

        return DB::table('congress_election_histories')
            ->where('country_id', (int) $countryId)
            ->orderBy('finished_at', 'DESC')
            ->limit(max(1, (int) $limit))
            ->get()
            ->map(function ($row) {
                $summary = [];
                $winners = [];
                if (!empty($row->summary_json)) {
                    $decoded = json_decode($row->summary_json, true);
                    if (is_array($decoded)) {
                        $summary = $decoded;
                    }
                }

                if (!empty($row->winners_json)) {
                    $decodedWinners = json_decode($row->winners_json, true);
                    if (is_array($decodedWinners)) {
                        $winners = $decodedWinners;
                    }
                }

                $winnerNick = 'Secim sonuclandi';
                $winnerVotes = 0;
                if (!empty($winners[0]['uid'])) {
                    $winnerRow = $this->getUserRow((int) $winners[0]['uid']);
                    $winnerNick = $winnerRow->nick ?? ('ID ' . (int) $winners[0]['uid']);
                    $winnerVotes = (int) ($winners[0]['votes'] ?? 0);
                }

                return [
                    'winner_nick' => $winnerNick,
                    'winner_votes' => $winnerVotes,
                    'seat_count' => (int) ($row->seat_count ?? 0),
                    'candidate_count' => (int) ($row->candidate_count ?? 0),
                    'total_votes' => (int) ($row->total_votes ?? 0),
                    'finished_at' => $row->finished_at,
                    'summary' => $summary,
                    'winners' => $winners,
                ];
            })
            ->toArray();
    }

    private function ensureCongressElectionRow($countryId)
    {
        if (!DB::getSchemaBuilder()->hasTable('congress_elections')) {
            return null;
        }

        $phaseMeta = $this->getElectionPhaseMeta('congress');
        if (!in_array($phaseMeta['phase'], ['candidacy', 'review', 'voting', 'results'], true)) {
            return null;
        }

        $electionKey = $this->getElectionCycleKey();
        $row = DB::table('congress_elections')
            ->where('country_id', (int) $countryId)
            ->where('election_key', $electionKey)
            ->first();

        if ($row) {
            return $row;
        }

        if ($phaseMeta['phase'] === 'results') {
            return null;
        }

        $id = DB::table('congress_elections')->insertGetId([
            'country_id' => (int) $countryId,
            'election_key' => $electionKey,
            'status' => $phaseMeta['phase'],
            'seat_count' => $this->getCongressSeatCount($countryId),
            'created_at' => date('Y-m-d H:i:s'),
            'ends_at' => date('Y-m-d H:i:s', $this->getElectionWindowTimestamp(self::CONGRESS_RESULTS_DAY, true)),
        ]);

        return DB::table('congress_elections')->where('id', $id)->first();
    }

    private function finalizeCongressElectionRow($election)
    {
        $electionId = (int) $election->id;
        $countryId = (int) $election->country_id;
        $seatCount = (int) ($election->seat_count ?? $this->getCongressSeatCount($countryId));

        $candidateRows = DB::table('congress_election_candidates')
            ->where('election_id', $electionId)
            ->orderBy('votes', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->get();

        $summary = [];
        $totalVotes = 0;
        foreach ($candidateRows as $candidateRow) {
            $userRow = $this->getUserRow((int) $candidateRow->uid);
            $summary[] = [
                'uid' => (int) $candidateRow->uid,
                'nick' => $userRow->nick ?? ('ID ' . (int) $candidateRow->uid),
                'votes' => (int) $candidateRow->votes,
                'party_id' => (int) ($candidateRow->party_id ?? 0),
            ];
            $totalVotes += (int) $candidateRow->votes;
        }

        $lists = $this->buildCongressElectionLists($candidateRows);
        $allocatedSeats = $this->allocateCongressSeatsFromLists($lists, $seatCount);
        $winners = [];

        DB::table('congress_members')->where('country', $countryId)->delete();
        foreach ($lists as $listKey => $list) {
            $listSeats = (int) ($allocatedSeats[$listKey] ?? 0);
            if ($listSeats < 1) {
                continue;
            }

            foreach (array_slice($list['candidates'], 0, $listSeats) as $candidateRow) {
                CongressMember::create([
                    'party' => (int) ($candidateRow['party_id'] ?? 0),
                    'uid' => (int) $candidateRow['uid'],
                    'country' => $countryId,
                ]);

                $winners[] = [
                    'uid' => (int) $candidateRow['uid'],
                    'votes' => (int) ($candidateRow['votes'] ?? 0),
                    'party_id' => (int) ($candidateRow['party_id'] ?? 0),
                    'list_label' => (string) ($list['label'] ?? 'Liste'),
                    'list_votes' => (int) ($list['total_votes'] ?? 0),
                    'list_seats' => $listSeats,
                ];
            }
        }

        $speakerUid = (int) (DB::table('countries')->where('id', $countryId)->value('speaker_uid') ?? 0);
        if ($speakerUid > 0 && !in_array($speakerUid, array_map(function ($winner) { return (int) $winner['uid']; }, $winners), true)) {
            DB::table('countries')->where('id', $countryId)->update(['speaker_uid' => null]);
        }

        if (DB::getSchemaBuilder()->hasTable('congress_election_histories')) {
            DB::table('congress_election_histories')->updateOrInsert(
                ['country_id' => $countryId, 'election_key' => $election->election_key],
                [
                    'seat_count' => $seatCount,
                    'candidate_count' => $candidateRows->count(),
                    'total_votes' => $totalVotes,
                    'summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                    'winners_json' => json_encode($winners, JSON_UNESCAPED_UNICODE),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        $archiveUrl = $this->getElectionArchiveUrl('congress');
        $countryName = (string) (DB::table('countries')->where('id', $countryId)->value('name') ?? 'Ulke');
        $winnerUserIds = array_values(array_unique(array_map(function ($winnerRow) {
            return (int) ($winnerRow['uid'] ?? 0);
        }, $winners)));

        if (!empty($winnerUserIds)) {
            $this->pushElectionNotifications(
                $winnerUserIds,
                'election',
                'Kongreye secildin',
                $countryName . ' meclisinde yeni donemde gorev alacaksin.',
                $archiveUrl,
                ['scope' => 'congress', 'country_id' => $countryId]
            );
        }

        $citizenRecipients = array_diff($this->getCitizenUserIdsForCountry($countryId), $winnerUserIds);
        $this->pushElectionNotifications(
            $citizenRecipients,
            'election',
            'Kongre secimi sonuclandi',
            count($winnerUserIds) . ' aday yeni meclise girdi. Sandalyeler parti listesi esasina gore dagitildi.',
            $archiveUrl,
            ['scope' => 'congress', 'country_id' => $countryId]
        );

        DB::table('congress_elections')->where('id', $electionId)->update([
            'status' => 'finished',
            'finished_at' => date('Y-m-d H:i:s'),
            'ends_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    private function finalizeScheduledCongressElections($countryId = null)
    {
        if (!DB::getSchemaBuilder()->hasTable('congress_elections')) {
            return 0;
        }

        $query = DB::table('congress_elections')->whereNotNull('election_key');
        if ($countryId !== null) {
            $query->where('country_id', (int) $countryId);
        }

        $finalized = 0;
        foreach ($query->get() as $election) {
            $resultTimestamp = $this->getElectionWindowTimestamp(self::CONGRESS_RESULTS_DAY, false, strtotime($election->election_key));
            if (time() < $resultTimestamp) {
                continue;
            }
            if (($election->status ?? '') === 'finished') {
                continue;
            }
            if ($this->finalizeCongressElectionRow($election)) {
                $finalized++;
            }
        }

        return $finalized;
    }

    private function getCongressCandidateRequirements($countryId, $uid)
    {
        $user = $this->getUserRow($uid);
        $partyMembership = $this->getPartyMembershipRow($uid);
        $requirements = [
            [
                'label' => 'Vatandaslik',
                'ok' => $this->isCitizenOfCountry($uid, $countryId),
                'hint' => 'Sadece kendi ulkenin vatandasi kongre adayi olabilir.',
            ],
            [
                'label' => 'Seviye ' . self::CONGRESS_MIN_LEVEL . '+',
                'ok' => (int) ($user->level ?? 0) >= self::CONGRESS_MIN_LEVEL,
                'hint' => 'Kongre adayligi icin minimum oyuncu seviyesi gerekir.',
            ],
            [
                'label' => 'Parti Uyeligi',
                'ok' => !empty($partyMembership->party),
                'hint' => 'Aday listelerini parti liderleri duzenledigi icin aktif parti uyeligi zorunlu.',
            ],
        ];

        $eligible = true;
        foreach ($requirements as $requirement) {
            if (!$requirement['ok']) {
                $eligible = false;
                break;
            }
        }

        return [
            'eligible' => $eligible,
            'items' => $requirements,
        ];
    }

    public function runScheduledElectionMaintenance()
    {
        return [
            'presidential_finalized' => $this->finalizeExpiredPresidentialElection(),
            'party_finalized' => $this->finalizeExpiredPartyElections(),
            'congress_finalized' => $this->finalizeScheduledCongressElections(),
        ];
    }

    public function showLawProposal ($id)
    {
        if (!DB::getSchemaBuilder()->hasTable('law_proposals')) {
            throw new AppException(AppException::ACTION_FAILED, 'Meclis oylama sistemi henuz hazir degil.');
        }
        $law = LawProposal::where('id', $id)->first();
        if (!$law) { throw new AppException(AppException::INVALID_DATA); }
        if ($law->finished == 0) {
            $this->checkPendingLaws($law->country);
            $law = LawProposal::where('id', $id)->first();
            if (!$law) { throw new AppException(AppException::INVALID_DATA); }
        }

        $law->phrase = $this->buildLawPhrase($law);
        $law->expires_at_formatted = date('d.m.Y H:i', strtotime($law->created_at) + (24 * 3600));

        $viewerUid = App::user()->getUid();
        $schema = DB::getSchemaBuilder();
        $hasCongressMembersTable = $schema->hasTable('congress_members');
        $isCurrentCongressMember = $hasCongressMembersTable && CongressMember::where([
            'uid' => $viewerUid,
            'country' => $law->country,
        ])->exists();
        $hasLawVotesTable = $schema->hasTable('law_votes');
        $myVote = $hasLawVotesTable ? LawVote::where(['law' => $law->id, 'uid' => $viewerUid])->first() : null;
        $canVote = !$law->finished && $isCurrentCongressMember && !$myVote && $hasLawVotesTable;

        $countryObj = DB::table('countries')->where('id', $law->country)->first();
        $isOhalActive = $countryObj && property_exists($countryObj, 'ohal_until') && $countryObj->ohal_until && strtotime($countryObj->ohal_until) > time();

        $myAffiliation = App::user()->getPoliticalParty();
        $myPartyId = $myAffiliation ? $myAffiliation->party : 0;
        $isPartyLeader = false; $whipDecision = null;

        if ($myPartyId) {
            $party = DB::table('political_parties')->where('id', $myPartyId)->first();
            if ($party && $party->uid == App::user()->getUid()) { $isPartyLeader = true; }
            $whip = $schema->hasTable('law_whips')
                ? DB::table('law_whips')->where('law_id', $id)->where('party_id', $myPartyId)->first()
                : null;
            if ($whip) { $whipDecision = $whip->decision; }
        }

        return $this->render('congress/law_proposal.html.twig', [
            "law" => $law, "isCongressist" => $isCurrentCongressMember, "isOhalActive" => $isOhalActive,
            "isPartyLeader" => $isPartyLeader, "whipDecision" => $whipDecision, "myPartyId" => $myPartyId,
            "canVote" => $canVote, "myVote" => $myVote
        ]);
    }

    public function showHome ()
    {
        $cId = $this->getElectionCountry();
        $this->runScheduledElectionMaintenance();
        $schema = DB::getSchemaBuilder();
        $hasLawProposalsTable = $schema->hasTable('law_proposals');
        $hasLawVotesTable = $schema->hasTable('law_votes');
        $hasCongressMembersTable = $schema->hasTable('congress_members');
        if ($hasLawProposalsTable) {
            $this->checkPendingLaws($cId);
        }
        $uid = App::user()->getUid();
        $isCongressist = $hasCongressMembersTable && CongressMember::where(['uid' => $uid, 'country' => $cId])->exists();

        $countryObj = DB::table('countries')->where('id', $cId)->first();
        $actualPresId = $this->getActualPresidentId($cId);
        $presidentUser = null; $speakerUser = null;

        if ($actualPresId > 0) {
            $pUser = DB::table('users')->where('id', $actualPresId)->first();
            if ($pUser) {
                $presidentUser = [
                    'id' => $pUser->id,
                    'nick' => $pUser->nick,
                    'avatar' => $pUser->avatar ?? null,
                ];
                try { DB::table('countries')->where('id', $cId)->update(['president' => $actualPresId]); } catch(\Exception $e) {}
            }
        }
        if ($countryObj && !empty($countryObj->speaker_uid)) {
            $sUser = DB::table('users')->where('id', $countryObj->speaker_uid)->first();
            if ($sUser) {
                $speakerUser = [
                    'id' => $sUser->id,
                    'nick' => $sUser->nick,
                    'avatar' => $sUser->avatar ?? null,
                ];
            }
        }

        $latestLawsRaw = $hasLawProposalsTable
            ? LawProposal::where(["country" => $cId])->orderBy('id', 'DESC')->limit(15)->get()
            : collect();
        $lawIds = $latestLawsRaw->pluck('id')->all();
        $myVotesByLaw = [];
        if ($hasLawVotesTable && !empty($lawIds)) {
            $myVotesByLaw = LawVote::where('uid', $uid)->whereIn('law', $lawIds)->pluck('in_favor', 'law')->all();
        }
        $isCurrentCongressMember = $hasCongressMembersTable && CongressMember::where(['uid' => $uid, 'country' => $cId])->exists();
        $latestLaws = [];
        foreach($latestLawsRaw as $l) {
            $ld = $l->toArray();
            $ld['phrase'] = $this->buildLawPhrase($ld);
            $ld['required_majority'] = $this->getLawMajorityRules($ld['type'], $ld['is_vetoed'] ?? 0, $cId);
            $ld['expires_at_formatted'] = date('d.m.Y H:i', strtotime($ld['created_at']) + (24 * 3600));
            $ld['has_my_vote'] = array_key_exists($ld['id'], $myVotesByLaw);
            $ld['my_vote'] = $ld['has_my_vote'] ? (int) $myVotesByLaw[$ld['id']] : null;
            $ld['can_vote'] = empty($ld['finished']) && $isCurrentCongressMember && !$ld['has_my_vote'] && $hasLawVotesTable;
            $latestLaws[] = $ld;
        }

        $myAffiliation = App::user()->getPoliticalParty();
        $myCoalitionId = null; $isCoalitionFounder = false;
        if ($myAffiliation) {
            $myParty = DB::table('political_parties')->where('id', $myAffiliation->party)->first();
            if ($myParty && !empty($myParty->coalition_id)) {
                $myCoalitionId = $myParty->coalition_id;
                $coalition = DB::table('coalitions')->where('id', $myCoalitionId)->first();
                if ($coalition && $coalition->founder_party_id == $myParty->id && $myParty->uid == App::user()->getUid()) { $isCoalitionFounder = true; }
            }
        }

        foreach ($latestLaws as &$law) {
            $law["is_embargoed"] = false; $law["can_veto"] = false;
            $lawmakerAffiliation = DB::table('party_members')->where('uid', $law['uid'])->first();
            if ($lawmakerAffiliation && $myCoalitionId) {
                $hasEmbargo = DB::table('coalition_embargos')->where('coalition_id', $myCoalitionId)->where('target_party_id', $lawmakerAffiliation->party)->exists();
                if ($hasEmbargo) {
                    $law["is_embargoed"] = true;
                    if ($isCoalitionFounder && empty($law['finished'])) { $law["can_veto"] = true; }
                }
            }
        }

        $congressMembersRaw = $hasCongressMembersTable
            ? CongressMember::where(["country" => $cId])->orderBy('id', 'ASC')->get()->toArray()
            : [];
        $filledSeatCount = count($congressMembersRaw);
        $memberUserIds = array_values(array_unique(array_filter(array_map(function ($member) {
            return (int) ($member['uid'] ?? 0);
        }, $congressMembersRaw))));
        $representedPartyIds = array_values(array_unique(array_filter(array_map(function ($member) {
            return (int) ($member['party'] ?? 0);
        }, $congressMembersRaw))));

        $usersById = [];
        if (!empty($memberUserIds)) {
            foreach (DB::table('users')->whereIn('id', $memberUserIds)->get() as $userRow) {
                $usersById[(int) $userRow->id] = $userRow;
            }
        }
        $partiesById = [];
        if (!empty($representedPartyIds)) {
            foreach (DB::table('political_parties')->whereIn('id', $representedPartyIds)->get() as $partyRow) {
                $partiesById[(int) $partyRow->id] = $partyRow;
            }
        }

        $congressMembers = [];
        $partySeats = [];
        $myParliamentMembership = null;
        foreach ($congressMembersRaw as $member) {
            $memberUid = (int) ($member['uid'] ?? 0);
            $representedPartyId = (int) ($member['party'] ?? 0);
            $partyRow = $partiesById[$representedPartyId] ?? null;
            $isIndependent = $representedPartyId < 1 || !$partyRow;
            $groupKey = $isIndependent ? 'independent' : 'party_' . $representedPartyId;
            $groupName = $isIndependent ? 'Bagimsiz Temsilciler' : (string) $partyRow->name;

            if (!isset($partySeats[$groupKey])) {
                $partySeats[$groupKey] = [
                    'id' => $representedPartyId,
                    'name' => $groupName,
                    'count' => 0,
                    'is_independent' => $isIndependent,
                ];
            }
            $partySeats[$groupKey]['count']++;

            if ($memberUid === $uid) {
                $myParliamentMembership = [
                    'party_id' => $representedPartyId,
                    'party_name' => $groupName,
                    'is_independent' => $isIndependent,
                ];
            }

            $userRow = $usersById[$memberUid] ?? null;
            if ($userRow) {
                $congressMembers[] = [
                    'id' => (int) $userRow->id,
                    'nick' => (string) $userRow->nick,
                    'party_name' => $groupName,
                    'is_independent' => $isIndependent,
                    'is_viewer' => $memberUid === $uid,
                ];
            }
        }
        uasort($partySeats, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $seatCapacity = null;
        if (DB::getSchemaBuilder()->hasColumn('countries', 'congress_seat_count')) {
            $configuredSeatCount = (int) ($countryObj->congress_seat_count ?? 0);
            if ($configuredSeatCount > 0) {
                $seatCapacity = $configuredSeatCount;
            }
        }
        $representedPartyCount = count(array_filter($partySeats, function ($party) {
            return empty($party['is_independent']);
        }));

        $isOhalActive = $countryObj && property_exists($countryObj, 'ohal_until') && $countryObj->ohal_until && strtotime($countryObj->ohal_until) > time();
        $isEmergencySession = $countryObj && property_exists($countryObj, 'emergency_session_until') && $countryObj->emergency_session_until && strtotime($countryObj->emergency_session_until) > time();
        $isBordersClosed = $countryObj && property_exists($countryObj, 'borders_closed_until') && $countryObj->borders_closed_until && strtotime($countryObj->borders_closed_until) > time();
        $isMobilizationActive = $countryObj && property_exists($countryObj, 'mobilization_until') && $countryObj->mobilization_until && strtotime($countryObj->mobilization_until) > time();

        $isPres = ($actualPresId == $uid);
        $canUseProposalBox = $isPres || $isCongressist;
        $maxQuota = ($isOhalActive && $isPres) ? 999 : ($isPres ? 20 : 10);
        $proposedToday = DB::table('law_proposals')->where('uid', $uid)->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
        $remainingQuota = max(0, $maxQuota - $proposedToday);
        return $this->render('congress/home.html.twig', [
            "latestLaws" => $latestLaws, "congressMembers" => $congressMembers, "partySeats" => $partySeats,
            "filledSeatCount" => $filledSeatCount, "seatCapacity" => $seatCapacity, "representedPartyCount" => $representedPartyCount,
            "myParliamentMembership" => $myParliamentMembership,
            "isOhalActive" => $isOhalActive, "ohalUntil" => $countryObj->ohal_until ?? null,
            "isEmergencySession" => $isEmergencySession, "emergencyUntil" => $countryObj->emergency_session_until ?? null,
            "isBordersClosed" => $isBordersClosed, "isMobilizationActive" => $isMobilizationActive,
            "presidentUser" => $presidentUser, "speakerUser" => $speakerUser, "maxQuota" => $maxQuota,
            "remainingQuota" => $remainingQuota, "isPresident" => $isPres,
            "isCongressist" => $isCongressist, "canUseProposalBox" => $canUseProposalBox,
            "presidentOnlyLawTypes" => $this->getPresidentOnlyLawTypes(),
            "congressOnlyLawTypes" => $this->getCongressOnlyLawTypes(),
        ]);
    }

    public function showElections ()
    {
        $cId = $this->getElectionCountry();
        $this->runScheduledElectionMaintenance();

        $uid = App::user()->getUid();
        $actualPresId = $this->getActualPresidentId($cId);
        $countryObj = DB::table('countries')->where('id', $cId)->first();
        $presidentUser = null;
        $speakerUser = null;

        if ($actualPresId > 0) {
            $pUser = DB::table('users')->where('id', $actualPresId)->first();
            if ($pUser) {
                $presidentUser = [
                    'id' => $pUser->id,
                    'nick' => $pUser->nick,
                    'avatar' => $pUser->avatar ?? null,
                ];
            }
        }

        if ($countryObj && !empty($countryObj->speaker_uid)) {
            $sUser = DB::table('users')->where('id', $countryObj->speaker_uid)->first();
            if ($sUser) {
                $speakerUser = [
                    'id' => $sUser->id,
                    'nick' => $sUser->nick,
                    'avatar' => $sUser->avatar ?? null,
                ];
            }
        }

        return $this->render('congress/elections.html.twig', [
            "election" => $this->buildElectionData($cId, $uid),
            "presidentialElection" => $this->buildPresidentialElectionData($cId, $uid),
            "partyElection" => $this->buildPartyElectionData($uid),
            "presidentUser" => $presidentUser,
            "speakerUser" => $speakerUser,
            "isPresident" => ($actualPresId == $uid),
            "countryId" => $cId,
        ]);
    }

    public function showElectionArchive($type = null)
    {
        $cId = $this->getElectionCountry();
        $uid = App::user()->getUid();
        $membership = $this->getPartyMembershipRow($uid);
        $partyId = (int) ($membership->party ?? 0);
        $type = trim((string) $type);
        $activeType = in_array($type, ['presidential', 'party', 'congress'], true) ? $type : 'all';

        $sections = [];

        if ($activeType === 'all' || $activeType === 'presidential') {
            $sections[] = [
                'key' => 'presidential',
                'title' => 'Cumhurbaskanligi Arsivi',
                'items' => $this->getPresidentialElectionHistory($cId, 24),
                'empty' => 'Bu ulke icin kayitli baskanlik secimi bulunmuyor.',
            ];
        }

        if ($activeType === 'all' || $activeType === 'party') {
            $sections[] = [
                'key' => 'party',
                'title' => 'Parti Liderligi Arsivi',
                'items' => $partyId > 0 ? $this->getPartyElectionHistory($partyId, 24) : [],
                'empty' => $partyId > 0
                    ? 'Bu parti icin kayitli liderlik secimi bulunmuyor.'
                    : 'Parti liderligi arsivi icin aktif bir parti uyeligi gerekiyor.',
            ];
        }

        if ($activeType === 'all' || $activeType === 'congress') {
            $sections[] = [
                'key' => 'congress',
                'title' => 'Kongre Arsivi',
                'items' => $this->getCongressElectionHistory($cId, 24),
                'empty' => 'Bu ulke icin kayitli kongre secimi bulunmuyor.',
            ];
        }

        return $this->render('congress/election_archive.html.twig', [
            'sections' => $sections,
            'activeType' => $activeType,
            'countryId' => $cId,
            'partyId' => $partyId,
        ]);
    }

    private function getOwnCountry () {
        if (empty($this->ownCountry)) {
            $countryId = 0;

            try {
                $location = App::user()->getLocation();
                $countryId = (int) ($location['country']['id'] ?? 0);
            } catch (\Throwable $e) {
                $countryId = 0;
            }

            if ($countryId < 1) {
                $affiliation = App::user()->getPoliticalParty();
                $countryId = $affiliation ? (int) ($affiliation->partyData->country ?? 0) : 0;
            }

            $this->ownCountry = $countryId;
        }
        return $this->ownCountry;
    }

    private function getElectionCountry()
    {
        if ($this->electionCountry === null) {
            $citizenCountryId = (int) $this->getUserCitizenCountryId(App::user()->getUid());
            $this->electionCountry = $citizenCountryId > 0 ? $citizenCountryId : (int) $this->getOwnCountry();
        }

        return (int) $this->electionCountry;
    }

    public function isPresident () { return ($this->getActualPresidentId($this->getElectionCountry()) == App::user()->getUid()); }
    public function mustBePresident () { if (!$this->isPresident()) { throw new AppException(AppException::ACCESS_DENIED); } }

    private function getPresidentOnlyLawTypes() {
        return [
            LawProposal::TYPE_NATURAL_ENEMY,
            LawProposal::TYPE_MUTUAL_PROTECTION_PACT,
            LawProposal::TYPE_CEASE_FIRE,
            LawProposal::TYPE_TRANSFER_FUNDS,
            self::TYPE_ELECT_PRESIDENT,
            self::TYPE_TRADE_EMBARGO,
            self::TYPE_BORDER_CONTROL,
            self::TYPE_MOBILIZATION,
            self::TYPE_SHOUT_BLACKOUT,
            9,
        ];
    }

    private function getCongressOnlyLawTypes() {
        return [
            LawProposal::TYPE_WORK_TAX,
            LawProposal::TYPE_MANAGER_TAX,
            8,
            self::TYPE_CUSTOMS_TARIFF,
            self::TYPE_ELECT_SPEAKER,
            LawProposal::TYPE_IMPEACHMENT,
            self::TYPE_EARLY_ELECTION,
            self::TYPE_LIFT_IMMUNITY,
            self::TYPE_OMNIBUS,
        ];
    }

    private function assertLawProposalPermission($type, $isPresident, $isCongressist) {
        if (!$isPresident && !$isCongressist) {
            throw new AppException(AppException::ACCESS_DENIED, "Yasa sunmak icin baskan veya aktif milletvekili olmalisiniz.");
        }

        if ($isPresident) {
            if (!in_array($type, $this->getPresidentOnlyLawTypes(), true)) {
                throw new AppException(AppException::ACCESS_DENIED, "Bu yasa turunu sadece milletvekilleri meclise sunabilir.");
            }
            return;
        }

        if (!in_array($type, $this->getCongressOnlyLawTypes(), true)) {
            throw new AppException(AppException::ACCESS_DENIED, "Bu yasa turunu sadece baskan meclise sunabilir.");
        }
    }

    public function presidentialVeto() {
        $this->mustBePresident();
        if (!DB::getSchemaBuilder()->hasTable('law_votes')) {
            throw new AppException(AppException::ACTION_FAILED, 'Meclis oy sistemi henuz hazir degil.');
        }
        $lawId = Input::getInteger('law_id');
        $law = LawProposal::where('id', $lawId)->first();
        if (!$law || $law->country != $this->getOwnCountry() || $law->finished == 1) { throw new AppException(AppException::INVALID_DATA, "Geçersiz yasa."); }
        if ($law->is_vetoed == 1) { throw new AppException(AppException::ACTION_FAILED, "Zaten veto edilmiş."); }
        
        $law->is_vetoed = 1; $law->yes = 0; $law->no = 0; $law->created_at = date('Y-m-d H:i:s'); $law->save();
        DB::table('law_votes')->where('law', $lawId)->delete();
        return true;
    }

    public function callEmergency() {
        $this->mustBePresident();
        DB::table('countries')->where('id', $this->getOwnCountry())->update(['emergency_session_until' => date('Y-m-d H:i:s', strtotime('+48 hours'))]);
        return true;
    }

    public function setWhip() {
        if (!DB::getSchemaBuilder()->hasTable('law_whips')) {
            throw new AppException(AppException::ACTION_FAILED, 'Grup karari sistemi henuz hazir degil.');
        }
        $lawId = Input::getInteger("law_id");
        $decision = Input::getString("decision");
        if (!in_array($decision, ['yes', 'no'])) throw new AppException(AppException::INVALID_DATA);
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        $myPartyId = $myAffiliation->party;
        $party = DB::table('political_parties')->where('id', $myPartyId)->first();
        if (!$party || $party->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED);
        $law = LawProposal::where('id', $lawId)->first();
        if ($law && (int) $law->country !== (int) $this->getOwnCountry()) {
            throw new AppException(AppException::ACCESS_DENIED);
        }
        if (!$law || $law->finished == 1) throw new AppException(AppException::INVALID_DATA, "Karar alınamaz.");
        DB::table('law_whips')->updateOrInsert(['law_id' => $lawId, 'party_id' => $myPartyId], ['decision' => $decision, 'created_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    public function voteLaw () {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('law_proposals') || !$schema->hasTable('law_votes')) {
            throw new AppException(AppException::ACTION_FAILED, 'Meclis oylama sistemi henuz hazir degil.');
        }
        $lawId = isset($_POST["id"]) ? (int)$_POST["id"] : Input::getInteger("law");
        $voteVal = isset($_POST["value"]) ? $_POST["value"] : (isset($_POST["vote"]) ? $_POST["vote"] : "false");
        $inFavor = filter_var($voteVal, FILTER_VALIDATE_BOOLEAN);

        if ($lawId < 1) throw new AppException(AppException::INVALID_DATA);
        $lawProposal = LawProposal::where(["id" => $lawId])->first();
        if (!$lawProposal || $lawProposal->country != $this->getOwnCountry()) throw new AppException(AppException::INVALID_DATA);
        $this->checkPendingLaws($lawProposal->country);
        DB::beginTransaction();
        try {
            $lawProposal = LawProposal::where(["id" => $lawId])->lockForUpdate()->first();
            if (!$lawProposal || $lawProposal->country != $this->getOwnCountry()) {
                throw new AppException(AppException::INVALID_DATA);
            }
            if ($lawProposal->finished) throw new AppException(AppException::INVALID_DATA, "Oylama tamamlanmis.");
            if (!$schema->hasTable('congress_members')) {
                throw new AppException(AppException::ACTION_FAILED, 'Meclis uyelik sistemi henuz hazir degil.');
            }

            $isCurrentCongressMember = CongressMember::where([
                "uid" => App::user()->getUid(),
                "country" => $lawProposal->country,
            ])->exists();
            if (!$isCurrentCongressMember) throw new AppException(AppException::ACTION_FAILED, "Bu oylamada oy kullanma yetkiniz yok.");

            $hasAlreadyVoted = LawVote::where(["law" => $lawId, "uid" => App::user()->getUid()])->exists();
            if ($hasAlreadyVoted) throw new AppException(AppException::INVALID_DATA);

            if ($inFavor) { $lawProposal->yes++; } else { $lawProposal->no++; }
            LawVote::create(["law" => $lawId, "uid" => App::user()->getUid(), "in_favor" => $inFavor]);

            $currentCount = CongressMember::where(["country" => $lawProposal->country])->count();
            $lawProposal->expected_votes = $currentCount;
            $totalVotes = $lawProposal->yes + $lawProposal->no;
            if ($totalVotes >= $lawProposal->expected_votes) { $lawProposal->finished = true; }

            if (!$lawProposal->save()) {
                throw new AppException(AppException::ACTION_FAILED);
            }
            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            throw $e instanceof AppException ? $e : new AppException(AppException::ACTION_FAILED);
        }

        if ($lawProposal->finished) {
            $reqMaj = $this->getLawMajorityRules($lawProposal->type, $lawProposal->is_vetoed, $lawProposal->country);
            $yesRatio = ($totalVotes > 0) ? ($lawProposal->yes / $totalVotes) * 100 : 0;
            if ($yesRatio >= $reqMaj && $lawProposal->yes > $lawProposal->no) {
                try { $this->applyLaw($lawProposal->id); } catch (\Exception $e) { }
            }
        }
        return true;
    }

    public function applyLaw($id) {
        $lawProposal = LawProposal::where(["id" => $id])->first();
        if (!$lawProposal) return;
        $m = $lawProposal->member ?? 0;

        switch($lawProposal->type) {
            case 8: DB::table('countries')->where('id', $lawProposal->country)->update(['minimum_wage' => $lawProposal->amount]); break;
            case 9: DB::table('countries')->where('id', $lawProposal->country)->update(['ohal_until' => date('Y-m-d H:i:s', strtotime('+3 days'))]); break;
            
            // --- SAVAŞ İLANI OTOMATİĞİ (BÖLGE HEDEFLİ) ---
            case LawProposal::TYPE_NATURAL_ENEMY:
                DB::table('wars')->insert([
                    'attacker_id' => $lawProposal->country,
                    'defender_id' => $lawProposal->target_country,
                    'target_region_id' => $lawProposal->amount > 0 ? $lawProposal->amount : null, // MÜHENDİSLİK HARİKASI: Amount içindeki bölge ID'si alınıyor
                    'attacker_damage' => 0,
                    'defender_damage' => 0,
                    'status' => 'active',
                    'ends_at' => date('Y-m-d H:i:s', strtotime('+24 hours'))
                ]);
                break;
                
            case LawProposal::TYPE_IMPEACHMENT:
                $speakerId = (int) (DB::table('countries')->where('id', $lawProposal->country)->value('speaker_uid') ?? 0);
                $nextPresidentId = ($speakerId > 0 && $speakerId !== (int) $m) ? $speakerId : 0;

                try {
                    DB::table('countries')->where('id', $lawProposal->country)->update(['president' => $nextPresidentId]);
                } catch(\Exception $e) {}
                if($m > 0) { CongressMember::where(["uid" => $m, "country" => $lawProposal->country])->delete(); $this->checkPendingLaws($lawProposal->country); }
                break;
            case self::TYPE_ELECT_SPEAKER: if ($m > 0) { try { DB::table('countries')->where('id', $lawProposal->country)->update(['speaker_uid' => $m]); } catch (\Exception $e) {} } break;
            case self::TYPE_LIFT_IMMUNITY: if($m > 0) { CongressMember::where(["uid" => $m, "country" => $lawProposal->country])->delete(); $this->checkPendingLaws($lawProposal->country); } break;
            case self::TYPE_ELECT_PRESIDENT: if ($m > 0) { try { DB::table('countries')->where('id', $lawProposal->country)->update(['president' => $m]); } catch (\Exception $e) {} } break;
            case self::TYPE_OMNIBUS: 
                if (preg_match('/\[W:([0-9\.]+) M:([0-9\.]+) MW:([0-9\.]+)\]/', $lawProposal->reason, $mt)) {
                    $taxW = Tax::where(["country" => $lawProposal->country, "type" => LawProposal::TYPE_WORK_TAX])->first();
                    if ($taxW) { $taxW->amount = $mt[1]; $taxW->save(); }
                    $taxM = Tax::where(["country" => $lawProposal->country, "type" => LawProposal::TYPE_MANAGER_TAX])->first();
                    if ($taxM) { $taxM->amount = $mt[2]; $taxM->save(); }
                    try { DB::table('countries')->where('id', $lawProposal->country)->update(['minimum_wage' => $mt[3]]); } catch (\Exception $e) {}
                }
                break;
            case self::TYPE_TRADE_EMBARGO: 
                DB::table('trade_embargos')->updateOrInsert(
                    ['country_id' => $lawProposal->country, 'target_country_id' => $lawProposal->target_country],
                    ['expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))]
                );
                break;
            case self::TYPE_BORDER_CONTROL:
                try { DB::table('countries')->where('id', $lawProposal->country)->update(['borders_closed_until' => date('Y-m-d H:i:s', strtotime('+3 days'))]); } catch (\Exception $e) {}
                break;
            case self::TYPE_MOBILIZATION:
                try { DB::table('countries')->where('id', $lawProposal->country)->update(['mobilization_until' => date('Y-m-d H:i:s', strtotime('+2 days'))]); } catch (\Exception $e) {}
                break;
            case self::TYPE_SHOUT_BLACKOUT:
                try {
                    if (DB::getSchemaBuilder()->hasTable('country_media_blackouts') && DB::getSchemaBuilder()->hasTable('user_money')) {
                        $countryId = (int) $lawProposal->country;
                        $proposerUid = (int) $lawProposal->uid;
                        $now = date('Y-m-d H:i:s');
                        $activeBlackout = DB::table('country_media_blackouts')
                            ->where('country_id', $countryId)
                            ->where('expires_at', '>', $now)
                            ->first();

                        if (!$activeBlackout) {
                            $money = DB::table('user_money')->where('uid', $proposerUid)->first();
                            if ($money && (float) ($money->gold ?? 0) >= 100) {
                                DB::table('user_money')->where('uid', $proposerUid)->update([
                                    'gold' => round((float) ($money->gold ?? 0) - 100, 2),
                                ]);

                                DB::table('country_media_blackouts')->insert([
                                    'country_id' => $countryId,
                                    'activated_by' => $proposerUid,
                                    'source_shout_id' => null,
                                    'cost_currency' => 'Gold',
                                    'cost_amount' => 100,
                                    'starts_at' => $now,
                                    'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day')),
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {}
                break;
        }
    }
    
    public function proposeLaw() {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('law_proposals')) {
            throw new AppException(AppException::ACTION_FAILED, 'Meclis oylama sistemi henuz hazir degil.');
        }
        $type = Input::getInteger("type");
        $reason = Input::getString("reason", true);
        $country = Input::getInteger("country");
        $amount = Input::getFloat("amount");
        $currency = strtolower(Input::getString("currency"));
        $member = Input::getInteger("uid");
        
        // YENİ: Arayüzden gelen bölge ID'sini yakala
        $region_id = Input::getInteger("region_id"); 
        
        $uid = App::user()->getUid();
        $lawsCountry = $this->getOwnCountry();

        $validTypes = is_array(LawProposal::$validLawTypes) ? array_merge(LawProposal::$validLawTypes, [8, 9, 11, 12, 13, 14, 15, 16, 17, 19, 20, 21, 22]) : [1,2,3,4,5,6,7,8,9,11,12,13,14,15,16,17,19,20,21,22];
        if (!in_array($type, $validTypes) || empty($reason)) throw new AppException(AppException::INVALID_DATA, "Geçersiz yasa veya boş gerekçe.");
        if (($type == self::TYPE_TRADE_EMBARGO || $type == LawProposal::TYPE_NATURAL_ENEMY) && $country < 1) throw new AppException(AppException::INVALID_DATA, "Hedef ülke seçmelisiniz.");
        if (($type == self::TYPE_TRADE_EMBARGO || $type == LawProposal::TYPE_NATURAL_ENEMY)
            && !DB::table('countries')->where('id', $country)->exists()) {
            throw new AppException(AppException::INVALID_DATA, "Hedef ülke bulunamadı.");
        }

        // BÖLGE MÜHRÜ: Eğer Savaş İlanıysa, bölge ID'sini "amount" (Miktar) sütununa gizlice kaydet.
        if ($type == LawProposal::TYPE_NATURAL_ENEMY && $region_id > 0) {
            $amount = $region_id; 
        }

        $countryObj = DB::table('countries')->where('id', $lawsCountry)->first();
        $isOhalActive = $countryObj && property_exists($countryObj, 'ohal_until') && $countryObj->ohal_until && strtotime($countryObj->ohal_until) > time();
        $isPresident = $this->isPresident();
        $isCongressist = $schema->hasTable('congress_members') && CongressMember::where(['uid' => $uid, 'country' => $lawsCountry])->exists();

        if ($isOhalActive && !$isPresident) throw new AppException(AppException::ACCESS_DENIED, "OHAL devrede! Sadece Başkan yasa sunabilir.");
        $this->assertLawProposalPermission($type, $isPresident, $isCongressist);

        if ($type == self::TYPE_ELECT_PRESIDENT) {
            $currentPresidentId = $this->getActualPresidentId($lawsCountry);
            if (!$isPresident) {
                throw new AppException(AppException::ACCESS_DENIED, "Baskan atamasi niteligindeki bu ozel yasa teklifini sadece baskan sunabilir.");
            }

            if (!$isOhalActive && $currentPresidentId > 0) {
                throw new AppException(AppException::ACTION_FAILED, "Normal sartlarda baskanlik degisimi halk oylamasi ile yapilir. Bu yasa sadece acil veya makamin bos oldugu durumda kullanilabilir.");
            }
        }

        if ($type == self::TYPE_SHOUT_BLACKOUT) {
            if (!$isPresident) throw new AppException(AppException::ACCESS_DENIED, "Shout Karartmasi teklifini sadece baskan sunabilir.");
            if (!DB::getSchemaBuilder()->hasTable('country_media_blackouts')) throw new AppException(AppException::ACTION_FAILED, "Shout karartmasi sistemi henuz hazir degil.");

            $activeBlackout = DB::table('country_media_blackouts')
                ->where('country_id', $lawsCountry)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->first();

            if ($activeBlackout) throw new AppException(AppException::ACTION_FAILED, "Bu ulkede zaten aktif bir Shout Karartmasi var.");

            $presidentMoney = DB::table('user_money')->where('uid', $uid)->first();
            if (!$presidentMoney || (float) ($presidentMoney->gold ?? 0) < 100) {
                throw new AppException(AppException::ACTION_FAILED, "Bu yasayi meclise sunmak icin baskanin cebinde en az 100 Gold bulunmali.");
            }

            $amount = 24;
        }

        $maxQuota = ($isOhalActive && $isPresident) ? 999 : ($isPresident ? 20 : 10);
        $proposedToday = DB::table('law_proposals')->where('uid', $uid)->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($proposedToday >= $maxQuota) throw new AppException(AppException::INVALID_DATA, "Günlük yasa sunma kotanızı doldurdunuz.");

        if ($type == self::TYPE_OMNIBUS) {
            $tW = Input::getFloat("torba_w") ?? 0;
            $tM = Input::getFloat("torba_m") ?? 0;
            $tMW = Input::getFloat("torba_mw") ?? 0;
            $reason = "[W:$tW M:$tM MW:$tMW] " . $reason;
        }

        $expectedVotes = $schema->hasTable('congress_members')
            ? CongressMember::where(["country" => $lawsCountry])->count()
            : 0;
        
        try {
            $proposalId = DB::table('law_proposals')->insertGetId([
                "uid" => $uid, "type" => $type, "country" => $lawsCountry,
                "reason" => $reason, "target_country" => $country, "member" => $member,
                "amount" => $amount, "currency" => $currency ?: 'local',
                "expected_votes" => $expectedVotes, "finished" => 0, "is_secret" => 0, "is_vetoed" => 0,
                "created_at" => date('Y-m-d H:i:s'), "updated_at" => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) { throw new AppException(AppException::ACTION_FAILED, "Tasarı kaydedilemedi."); }

        if ($proposalId) { 
            $created = LawProposal::where('id', $proposalId)->first();
            if ($isOhalActive && $isPresident && $created) {
                $created->yes = $expectedVotes > 0 ? $expectedVotes : 1;
                $created->no = 0; $created->finished = 1; $created->save();
                try { $this->applyLaw($created->id); } catch (\Exception $e) {}
            }
            return $proposalId; 
        }
        throw new AppException(AppException::ACTION_FAILED, "Yasa oluşturulamadı.");
    }

    public function submitPresidentialApplication()
    {
        if (!DB::getSchemaBuilder()->hasTable('presidential_candidates')) {
            throw new AppException(AppException::ACTION_FAILED, "Baskanlik secimi sistemi henuz hazir degil.");
        }

        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();
        $campaignTitle = trim(Input::getString('campaign_title', true));
        $campaignMessage = trim(Input::getString('campaign_message', true));
        if ($countryId < 1) {
            throw new AppException(AppException::INVALID_DATA, "Ulke bilgisi bulunamadi.");
        }

        $requirements = $this->getPresidentialCandidateRequirements($countryId, $uid);
        if (!$requirements['eligible']) {
            throw new AppException(AppException::ACCESS_DENIED, "Baskanlik adaylik sartlarini henuz karsilamiyorsun.");
        }

        if (mb_strlen($campaignTitle) < 3) {
            throw new AppException(AppException::INVALID_DATA, "Kampanya basligi en az 3 karakter olmali.");
        }

        if (mb_strlen($campaignMessage) < 12) {
            throw new AppException(AppException::INVALID_DATA, "Propaganda metni en az 12 karakter olmali.");
        }

        if ($this->getElectionPhaseMeta('presidential')['phase'] !== 'candidacy') {
            throw new AppException(AppException::ACTION_FAILED, "Baskanlik adayligi sadece her ayin 2-4 gunleri arasinda aciktir.");
        }

        $this->finalizeExpiredPresidentialElection($countryId);
        $electionKey = $this->getElectionCycleKey();

        $existing = PresidentialCandidate::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->where('uid', $uid)
            ->first();

        if ($existing) {
            throw new AppException(AppException::ACTION_FAILED, "Bu secim turunda zaten adaysin.");
        }

        PresidentialCandidate::create([
            'uid' => $uid,
            'country' => $countryId,
            'election_key' => $electionKey,
            'votes' => 0,
            'campaign_title' => mb_substr($campaignTitle, 0, 80),
            'campaign_message' => mb_substr($campaignMessage, 0, 240),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function votePresidentialCandidate()
    {
        if (!DB::getSchemaBuilder()->hasTable('presidential_candidates') || !DB::getSchemaBuilder()->hasTable('presidential_votes')) {
            throw new AppException(AppException::ACTION_FAILED, "Baskanlik secimi sistemi henuz hazir degil.");
        }

        $candidateUid = Input::getInteger('candidate');
        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();

        $this->finalizeExpiredPresidentialElection($countryId);

        if (!$this->isCitizenOfCountry($uid, $countryId)) {
            throw new AppException(AppException::ACCESS_DENIED, "Baskanlik seciminde sadece kendi ulkenin vatandasi oy kullanabilir.");
        }

        $electionKey = $this->getElectionCycleKey();
        if ($this->getElectionPhaseMeta('presidential')['phase'] !== 'voting') {
            throw new AppException(AppException::ACTION_FAILED, "Baskanlik halk oylamasi sadece her ayin 5. gunu aciktir.");
        }

        $candidate = PresidentialCandidate::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->where('uid', $candidateUid)
            ->first();

        if (!$candidate) {
            throw new AppException(AppException::INVALID_DATA, "Gecersiz baskan adayi.");
        }

        $alreadyVoted = PresidentialVote::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->where('uid', $uid)
            ->first();

        if ($alreadyVoted) {
            throw new AppException(AppException::ACTION_FAILED, "Bu secim turunda zaten oy kullandin.");
        }

        PresidentialVote::create([
            'candidate_uid' => $candidateUid,
            'uid' => $uid,
            'country' => $countryId,
            'election_key' => $electionKey,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $candidate->votes = (int) $candidate->votes + 1;
        $candidate->save();

        return true;
    }

    public function resignPresidentialApplication()
    {
        if (!DB::getSchemaBuilder()->hasTable('presidential_candidates')) {
            throw new AppException(AppException::ACTION_FAILED, "Baskanlik secimi sistemi henuz hazir degil.");
        }

        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();

        $this->finalizeExpiredPresidentialElection($countryId);

        $electionKey = $this->getElectionCycleKey();
        if ($this->getElectionPhaseMeta('presidential')['phase'] !== 'candidacy') {
            throw new AppException(AppException::ACTION_FAILED, "Adayliktan cekilme sadece her ayin 2-4 gunleri arasinda yapilabilir.");
        }

        $candidate = PresidentialCandidate::where('country', $countryId)
            ->where('election_key', $electionKey)
            ->where('uid', $uid)
            ->first();

        if (!$candidate) {
            throw new AppException(AppException::ACTION_FAILED, "Aktif baskanlik adayligin bulunmuyor.");
        }

        $candidate->delete();

        return true;
    }

    public function submitPartyLeaderApplication()
    {
        if (!DB::getSchemaBuilder()->hasTable('party_elections') || !DB::getSchemaBuilder()->hasTable('party_candidates')) {
            throw new AppException(AppException::ACTION_FAILED, 'Parti liderligi secimi sistemi henuz hazir degil.');
        }

        if ($this->getElectionPhaseMeta('party')['phase'] !== 'candidacy') {
            throw new AppException(AppException::ACTION_FAILED, 'Parti liderligi adayligi sadece her ayin 12-14 gunleri arasinda aciktir.');
        }

        $uid = App::user()->getUid();
        $membership = $this->getPartyMembershipRow($uid);
        if (!$membership || empty($membership->party)) {
            throw new AppException(AppException::ACTION_FAILED, 'Parti liderligi secimi icin aktif bir parti uyeligin olmali.');
        }

        $partyId = (int) $membership->party;
        $this->finalizeExpiredPartyElections($partyId);
        $election = $this->ensurePartyElectionRow($partyId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif parti secim turu bulunamadi.');
        }

        $exists = DB::table('party_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $uid)
            ->exists();
        if ($exists) {
            throw new AppException(AppException::ACTION_FAILED, 'Bu turda zaten adaysin.');
        }

        DB::table('party_candidates')->insert([
            'election_id' => (int) $election->id,
            'uid' => $uid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function votePartyLeaderCandidate()
    {
        if (!DB::getSchemaBuilder()->hasTable('party_elections') || !DB::getSchemaBuilder()->hasTable('party_votes')) {
            throw new AppException(AppException::ACTION_FAILED, 'Parti liderligi secimi sistemi henuz hazir degil.');
        }

        if ($this->getElectionPhaseMeta('party')['phase'] !== 'voting') {
            throw new AppException(AppException::ACTION_FAILED, 'Parti liderligi oylamasi sadece her ayin 15. gunu aciktir.');
        }

        $uid = App::user()->getUid();
        $candidateUid = Input::getInteger('candidate');
        $membership = $this->getPartyMembershipRow($uid);
        if (!$membership || empty($membership->party)) {
            throw new AppException(AppException::ACCESS_DENIED, 'Bu secimde sadece aktif parti uyeleri oy kullanabilir.');
        }

        $partyId = (int) $membership->party;
        $election = $this->ensurePartyElectionRow($partyId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif parti secimi bulunamadi.');
        }

        $candidate = DB::table('party_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $candidateUid)
            ->first();
        if (!$candidate) {
            throw new AppException(AppException::INVALID_DATA, 'Gecersiz parti lideri adayi.');
        }

        $alreadyVoted = DB::table('party_votes')
            ->where('election_id', (int) $election->id)
            ->where('voter_uid', $uid)
            ->exists();
        if ($alreadyVoted) {
            throw new AppException(AppException::ACTION_FAILED, 'Bu turda zaten oy kullandin.');
        }

        DB::table('party_votes')->insert([
            'election_id' => (int) $election->id,
            'voter_uid' => $uid,
            'candidate_uid' => $candidateUid,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function resignPartyLeaderApplication()
    {
        if ($this->getElectionPhaseMeta('party')['phase'] !== 'candidacy') {
            throw new AppException(AppException::ACTION_FAILED, 'Adayliktan cekilme sadece her ayin 12-14 gunleri arasinda aciktir.');
        }

        $uid = App::user()->getUid();
        $membership = $this->getPartyMembershipRow($uid);
        if (!$membership || empty($membership->party)) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif parti uyeligin bulunmuyor.');
        }

        $partyId = (int) $membership->party;
        $election = $this->ensurePartyElectionRow($partyId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif parti secimi bulunamadi.');
        }

        DB::table('party_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $uid)
            ->delete();

        return true;
    }

    public function submitApplication ()
    {
        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();
        if ($countryId < 1) {
            throw new AppException(AppException::INVALID_DATA, "Ulke bilgisi bulunamadi.");
        }

        if (!DB::getSchemaBuilder()->hasTable('congress_elections') || !DB::getSchemaBuilder()->hasTable('congress_election_candidates')) {
            throw new AppException(AppException::ACTION_FAILED, 'Kongre secimi sistemi henuz hazir degil.');
        }

        if ($this->getElectionPhaseMeta('congress')['phase'] !== 'candidacy') {
            throw new AppException(AppException::ACTION_FAILED, 'Kongre adayligi sadece her ayin 21-23 gunleri arasinda aciktir.');
        }

        $requirements = $this->getCongressCandidateRequirements($countryId, $uid);
        if (!$requirements['eligible']) {
            throw new AppException(AppException::ACCESS_DENIED, 'Kongre adaylik sartlarini henuz karsilamiyorsun.');
        }

        $partyMembership = $this->getPartyMembershipRow($uid);
        $election = $this->ensureCongressElectionRow($countryId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif kongre secim turu bulunamadi.');
        }

        if (CongressMember::where(['uid' => $uid, 'country' => $countryId])->exists()) {
            throw new AppException(AppException::ACTION_FAILED, 'Zaten aktif kongre uyesisin.');
        }

        $exists = DB::table('congress_election_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $uid)
            ->exists();
        if ($exists) {
            throw new AppException(AppException::ACTION_FAILED, 'Bu turda zaten aktif bir adayligin var.');
        }

        DB::table('congress_election_candidates')->insert([
            'election_id' => (int) $election->id,
            'uid' => $uid,
            'party_id' => (int) ($partyMembership->party ?? 0),
            'votes' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'message' => 'Kongre adayligin meclis secim listesine eklendi.',
        ];
    }

    public function voteCandidate ()
    {
        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();
        $candidateId = Input::getInteger('candidate');

        if (!DB::getSchemaBuilder()->hasTable('congress_elections') || !DB::getSchemaBuilder()->hasTable('congress_election_votes')) {
            throw new AppException(AppException::ACTION_FAILED, 'Kongre secimi sistemi henuz hazir degil.');
        }

        if ($this->getElectionPhaseMeta('congress')['phase'] !== 'voting') {
            throw new AppException(AppException::ACTION_FAILED, 'Kongre oylamasi sadece her ayin 25. gunu aciktir.');
        }

        if (!$this->isCitizenOfCountry($uid, $countryId)) {
            throw new AppException(AppException::ACCESS_DENIED, 'Kongre seciminde sadece kendi ulkenin vatandasi oy kullanabilir.');
        }

        $election = $this->ensureCongressElectionRow($countryId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif kongre secimi bulunamadi.');
        }

        $candidate = DB::table('congress_election_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $candidateId)
            ->first();

        if (!$candidate) {
            throw new AppException(AppException::INVALID_DATA, 'Gecersiz kongre adayi.');
        }

        if ((int) $candidate->uid === $uid) {
            throw new AppException(AppException::ACTION_FAILED, 'Kendi adayligina oy veremezsin.');
        }

        $alreadyVoted = DB::table('congress_election_votes')
            ->where('election_id', (int) $election->id)
            ->where('voter_uid', $uid)
            ->exists();
        if ($alreadyVoted) {
            throw new AppException(AppException::ACTION_FAILED, 'Bu turda zaten oy kullandin.');
        }

        DB::table('congress_election_votes')->insert([
            'election_id' => (int) $election->id,
            'voter_uid' => $uid,
            'candidate_uid' => $candidateId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        DB::table('congress_election_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $candidateId)
            ->update([
                'votes' => DB::raw('votes + 1'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'message' => 'Kongre oyun kaydedildi.',
        ];
    }

    public function resign()
    {
        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();
        $didSomething = false;

        if (DB::getSchemaBuilder()->hasTable('congress_elections') && DB::getSchemaBuilder()->hasTable('congress_election_candidates') && $this->getElectionPhaseMeta('congress')['phase'] === 'candidacy') {
            $election = $this->ensureCongressElectionRow($countryId);
            if ($election) {
                $deleted = DB::table('congress_election_candidates')
                    ->where('election_id', (int) $election->id)
                    ->where('uid', $uid)
                    ->delete();
                if ($deleted) {
                    $didSomething = true;
                }
            }
        }

        $member = CongressMember::where(['uid' => $uid, 'country' => $countryId])->first();
        if ($member) {
            $member->delete();
            DB::table('countries')
                ->where('id', $countryId)
                ->where('speaker_uid', $uid)
                ->update(['speaker_uid' => null]);
            $didSomething = true;
        }

        if (!$didSomething) {
            throw new AppException(AppException::ACTION_FAILED, "Ayrilabilecegin aktif bir adaylik veya kongre uyeligi yok.");
        }

        return [
            'message' => 'Secim pozisyonundan ayrildin.',
        ];
    }

    public function removeCongressCandidate()
    {
        if ($this->getElectionPhaseMeta('congress')['phase'] !== 'review') {
            throw new AppException(AppException::ACTION_FAILED, 'Aday listesi duzenleme sadece her ayin 24. gunu aciktir.');
        }

        $uid = App::user()->getUid();
        $countryId = $this->getElectionCountry();
        $candidateUid = Input::getInteger('candidate');
        $election = $this->ensureCongressElectionRow($countryId);
        if (!$election) {
            throw new AppException(AppException::ACTION_FAILED, 'Aktif kongre secimi bulunamadi.');
        }

        $candidate = DB::table('congress_election_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $candidateUid)
            ->first();
        if (!$candidate) {
            throw new AppException(AppException::INVALID_DATA, 'Aday bulunamadi.');
        }

        if (!$this->isPartyLeaderOf($uid, (int) ($candidate->party_id ?? 0))) {
            throw new AppException(AppException::ACCESS_DENIED, 'Sadece kendi partinin aday listesini duzenleyebilirsin.');
        }

        DB::table('congress_election_votes')
            ->where('election_id', (int) $election->id)
            ->where('candidate_uid', $candidateUid)
            ->delete();

        DB::table('congress_election_candidates')
            ->where('election_id', (int) $election->id)
            ->where('uid', $candidateUid)
            ->delete();

        return true;
    }
    public function paySalaries()
    {
        return $this->jsonOut([
            "error" => true,
            "message" => "Bu modul erken erisimde aktif degil."
        ]);
    }
    
    /**
     * BAŞKANLIK FESİH PROTOKOLÜ (REVOKE SYSTEM)
     * Aktif olan OHAL, Seferberlik, Sınır Kapatma gibi durumların bitiş tarihlerini sıfırlayarak anında sonlandırır.
     */
    public function revokeState() {
        $type = Input::getString('type');
        $uid = App::user()->getUid();
        
        // Kullanıcının bulunduğu ülkeyi bul
        $userCountryId = App::user()->getLocation()["country"]["id"];
        $country = DB::table('countries')->where('id', $userCountryId)->first();
        
        if (!$country) {
            throw new AppException(AppException::INVALID_DATA, "Ülke verisine ulaşılamadı.");
        }
        
        // Yetki Kontrolü: İşlemi yapan kişi o ülkenin başkanı mı?
        if ($country->president != $uid) { 
            throw new AppException(AppException::INVALID_DATA, "ERİŞİM REDDEDİLDİ: Bu işlem için Başkomutan (Devlet Başkanı) yetkisi gerekir!");
        }

        // Karargah Veritabanı (countries) Sütun İsimlerine Göre Tarih Sıfırlama
        $updateData = [];
        if ($type === 'mobilization') {
            $updateData = ['mobilization_until' => null];
        } elseif ($type === 'borders') {
            $updateData = ['borders_closed_until' => null]; 
        } elseif ($type === 'emergency') {
            $updateData = ['emergency_session_until' => null];
        } elseif ($type === 'ohal') {
            $updateData = ['ohal_until' => null]; 
        } else {
            throw new AppException(AppException::INVALID_DATA, "Bilinmeyen fesih protokolü.");
        }

        // Veritabanını güncelle ve süreyi anında bitir
        try {
            DB::table('countries')->where('id', $userCountryId)->update($updateData);
            return ["success" => true];
        } catch (\Exception $e) {
            throw new AppException(AppException::ACTION_FAILED, "Karargah veritabanına ulaşılamadı.");
        }
    }
}

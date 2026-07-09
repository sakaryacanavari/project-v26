<?php

namespace App\Controllers;

use App\Models\PartyMember;
use App\Models\User;
use App\Models\UserMoney;
use App\System\App;
use App\System\ActionRateLimiter;
use App\System\AppException;
use App\System\Controller;
use App\System\Notify;
use App\System\Utils;
use App\Models\PoliticalParty as PartyModel;
use Illuminate\Database\Capsule\Manager as DB;

class PoliticalParty extends Controller
{
    const AD_COST = 20.0;
    const AD_PURCHASE_COOLDOWN_DAYS = 15;
    const PARTY_DAILY_APPLICATION_LIMIT = 10;
    const PARTY_DAILY_JOIN_LIMIT = 10;
    const PARTY_DAILY_LEAVE_LIMIT = 10;

    private function getAdCampaignOptions()
    {
        return [
            [
                'days' => 1,
                'label' => '1 Gun',
                'cost' => self::AD_COST,
                'badge' => 'Standart',
            ],
            [
                'days' => 3,
                'label' => '3 Gun',
                'cost' => 55.0,
                'badge' => 'Avantajli',
            ],
            [
                'days' => 7,
                'label' => '1 Hafta',
                'cost' => 120.0,
                'badge' => 'Komutan Paketi',
            ],
        ];
    }

    private function findAdCampaignOption($days)
    {
        foreach ($this->getAdCampaignOptions() as $option) {
            if ((int) $option['days'] === (int) $days) {
                return $option;
            }
        }

        return null;
    }

    private function getSafeUserName()
    {
        $uid = (int) App::user()->getUid();
        $user = User::where('id', $uid)->first();

        if ($user && method_exists($user, 'toArray')) {
            $arr = $user->toArray();
            return $arr['nick'] ?? $arr['username'] ?? $arr['name'] ?? ('Uye ' . $uid);
        }

        $u = App::user();
        if ($u && method_exists($u, 'toArray')) {
            $arr = $u->toArray();
            return $arr['nick'] ?? $arr['username'] ?? $arr['name'] ?? ('Uye ' . $uid);
        }

        return 'Uye ' . $uid;
    }

    private function resolveUserDisplayName($user, $fallback)
    {
        if (!$user) {
            return $fallback;
        }

        $userArray = method_exists($user, 'toArray') ? $user->toArray() : [];
        return trim((string)($userArray['nick'] ?? $userArray['username'] ?? $userArray['name'] ?? $fallback));
    }

    private function addLog($partyId, $message)
    {
        try {
            DB::table('party_logs')->insert([
                'party_id' => $partyId,
                'uid' => App::user()->getUid(),
                'message' => $message,
            ]);
        } catch (\Exception $e) {
        }
    }

    private function getJoinApplicationDisplayName($uid)
    {
        $user = User::where('id', $uid)->first();
        return $this->resolveUserDisplayName($user, 'Uye ' . $uid);
    }

    private function getStartOfToday()
    {
        return date('Y-m-d 00:00:00');
    }

    private function getPartyDailyActionCount($partyId, array $types)
    {
        try {
            return (int) DB::table('party_daily_actions')
                ->where('party_id', (int) $partyId)
                ->whereIn('action_type', $types)
                ->where('created_at', '>=', $this->getStartOfToday())
                ->count();
        } catch (\Exception $e) {
            throw new AppException(1, 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.');
        }
    }

    private function ensurePartyDailyCapacity($partyId, array $types, $limit, $message)
    {
        try {
            $count = $this->getPartyDailyActionCount($partyId, $types);
        } catch (\Exception $e) {
            return ['error' => 1, 'message' => 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.'];
        }

        if ($count >= (int) $limit) {
            return ['error' => 1, 'message' => $message];
        }

        return null;
    }

    private function recordPartyDailyAction($partyId, $uid, $type, array $meta = [])
    {
        DB::table('party_daily_actions')->insert([
            'party_id' => (int) $partyId,
            'uid' => (int) $uid,
            'action_type' => (string) $type,
            'meta' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function showCreationForm()
    {
        return $this->render('party/create.html.twig', ['page_title' => 'Siyasi Organizasyon Kur']);
    }

    public function showParty($id)
    {
        if ($id < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $party = PartyModel::where(['id' => $id])->first();
        if (!$party) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $partyArray = $party->toArray();
        $partyArray['members_count'] = PartyMember::where('party', $id)->count();
        $myAffiliation = App::user()->getPoliticalParty();
        if ($myAffiliation) {
            $partyArray['affiliation'] = $myAffiliation->toArray();
        }
        $partyArray['is_leader'] = ($party->uid == App::user()->getUid());
        $partyArray['ad_campaigns'] = $this->getAdCampaignOptions();
        $partyArray['ad_purchase_locked'] = false;
        $partyArray['ad_purchase_available_at'] = null;

        if (!empty($party->last_ad_purchase_at)) {
            $nextAdAt = strtotime($party->last_ad_purchase_at . ' +' . self::AD_PURCHASE_COOLDOWN_DAYS . ' days');
            if ($nextAdAt && $nextAdAt > time()) {
                $partyArray['ad_purchase_locked'] = true;
                $partyArray['ad_purchase_available_at'] = date('Y-m-d H:i:s', $nextAdAt);
            }
        }

        $membersData = PartyMember::where('party', $id)->get();
        $memberList = [];
        foreach ($membersData as $mb) {
            $u = User::where('id', $mb->uid)->first();
            $memberName = $this->resolveUserDisplayName($u, 'Üye ' . $mb->uid);
            $memberList[] = [
                'uid' => $mb->uid,
                'nick' => $memberName,
                'avatar' => $u ? (string) ($u->avatar ?? '') : '',
                'level' => (int) $mb->level,
            ];
        }
        $partyArray['member_list'] = $memberList;

        try {
            $partyArray['logs'] = DB::table('party_logs')
                ->where('party_id', $id)
                ->orderBy('created_at', 'DESC')
                ->limit(10)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            $partyArray['logs'] = [];
        }

        $partyArray['join_applications'] = [];
        $partyArray['my_pending_application'] = null;

        try {
            if ($partyArray['is_leader']) {
                $partyArray['join_applications'] = DB::table('party_join_applications as app')
                    ->leftJoin('users as u', 'u.id', '=', 'app.uid')
                    ->where('app.party_id', $id)
                    ->where('app.status', 'pending')
                    ->orderBy('app.created_at', 'ASC')
                    ->select(
                        'app.id',
                        'app.uid',
                        'app.message',
                        'app.created_at',
                        'u.nick',
                        'u.avatar'
                    )
                    ->get()
                    ->toArray();
            } elseif (!$myAffiliation) {
                $partyArray['my_pending_application'] = DB::table('party_join_applications')
                    ->where('party_id', $id)
                    ->where('uid', App::user()->getUid())
                    ->where('status', 'pending')
                    ->first();
            }
        } catch (\Exception $e) {
        }

        $electionQuery = DB::table('party_elections')
            ->where('party_id', $id)
            ->where('status', '!=', 'finished');

        if (DB::getSchemaBuilder()->hasColumn('party_elections', 'election_key')) {
            $electionQuery->where('election_key', date('Y-m-01 00:00:00'));
        }

        $election = $electionQuery->orderBy('id', 'DESC')->first();

        if ($election) {
            $election = (array) $election;
            $candidates = DB::table('party_candidates')->where('election_id', $election['id'])->get();
            $election['candidates'] = [];

            foreach ($candidates as $c) {
                $u = User::where('id', $c->uid)->first();
                $candName = $this->resolveUserDisplayName($u, 'Aday ' . $c->uid);
                $election['candidates'][] = ['uid' => $c->uid, 'name' => $candName];
            }

            $election['my_vote'] = DB::table('party_votes')
                ->where('election_id', $election['id'])
                ->where('voter_uid', App::user()->getUid())
                ->first();

            $partyArray['active_election'] = $election;
        }

        try {
            $partyArray['embargoes'] = DB::table('coalition_embargos')
                ->join('coalitions', 'coalitions.id', '=', 'coalition_embargos.coalition_id')
                ->where('coalition_embargos.target_party_id', $party->id)
                ->select('coalitions.name as coalition_name')
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            $partyArray['embargoes'] = [];
        }

        $partyArray['can_manage_embargo'] = false;
        $partyArray['my_coalition_embargo'] = false;

        if (!empty($party->coalition_id)) {
            $coalition = DB::table('coalitions')->where('id', $party->coalition_id)->first();
            if ($coalition) {
                $coalitionData = (array) $coalition;
                $coalitionData['members'] = DB::table('political_parties')
                    ->where('coalition_id', $coalition->id)
                    ->select('id', 'name', 'logo_url')
                    ->get()
                    ->toArray();

                $coalitionData['is_founder'] = false;
                $founderParty = PartyModel::where('id', $coalition->founder_party_id)->first();
                if ($founderParty && $founderParty->uid == App::user()->getUid()) {
                    $coalitionData['is_founder'] = true;
                }

                $partyArray['coalition'] = $coalitionData;
            }
        }

        if ($myAffiliation) {
            $myActualParty = PartyModel::where('id', $myAffiliation->party)->first();

            if (
                $myActualParty &&
                $party->id != $myActualParty->id &&
                empty($party->coalition_id) &&
                $myActualParty->uid == App::user()->getUid() &&
                !empty($myActualParty->coalition_id)
            ) {
                $partyArray['can_invite_to_coalition'] = true;
            }

            if ($myActualParty && !empty($myActualParty->coalition_id)) {
                $myCoalition = DB::table('coalitions')->where('id', $myActualParty->coalition_id)->first();
                if (
                    $myCoalition &&
                    $myCoalition->founder_party_id == $myActualParty->id &&
                    $myActualParty->uid == App::user()->getUid()
                ) {
                    if ($party->id != $myActualParty->id && $party->coalition_id != $myCoalition->id) {
                        $partyArray['can_manage_embargo'] = true;
                        try {
                            $partyArray['my_coalition_embargo'] = DB::table('coalition_embargos')
                                ->where('coalition_id', $myCoalition->id)
                                ->where('target_party_id', $party->id)
                                ->exists();
                        } catch (\Exception $e) {
                        }
                    }
                }
            }
        }

        if ($partyArray['is_leader'] && empty($party->coalition_id)) {
            try {
                $partyArray['pending_invites'] = DB::table('coalition_invites')
                    ->join('coalitions', 'coalitions.id', '=', 'coalition_invites.coalition_id')
                    ->join('political_parties', 'political_parties.id', '=', 'coalition_invites.inviter_party_id')
                    ->where('coalition_invites.target_party_id', $party->id)
                    ->where('coalition_invites.status', 'pending')
                    ->select(
                        'coalition_invites.id as invite_id',
                        'coalitions.name as coalition_name',
                        'political_parties.name as inviter_name'
                    )
                    ->get()
                    ->toArray();
            } catch (\Exception $e) {
            }
        }

        return $this->render('party/profile.html.twig', ['party' => $partyArray]);
    }

    public function showList()
    {
        $parties = PartyModel::where(['country' => App::user()->getLocation()['country']['id']])->get();
        $list = [];
        foreach ($parties as $p) {
            $pd = $p->toArray();
            $pd['members_count'] = PartyMember::where('party', $p->id)->count();
            $pd['is_featured'] = ($p->ad_until && strtotime($p->ad_until) > time());
            $list[] = $pd;
        }
        return $this->render('party/list.html.twig', ['list' => $list]);
    }

    public function create()
    {
        $blocked = ActionRateLimiter::throttle(
            'party_create',
            'uid:' . (int) App::user()->getUid(),
            3,
            3600,
            7200,
            'Parti kurma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        if (App::user()->getPoliticalParty()) {
            return ['error' => 1, 'message' => 'Zaten partidesiniz'];
        }

        $created = PartyModel::create([
            'name' => trim(strip_tags($_POST['name'])),
            'description' => trim(strip_tags($_POST['description'])),
            'country' => App::user()->getLocation()['country']['id'],
            'uid' => App::user()->getUid(),
        ]);

        if ($created) {
            PartyMember::create(['uid' => App::user()->getUid(), 'party' => $created->id, 'level' => 3]);
            return ['error' => 0, 'id' => $created->id];
        }

        return ['error' => 1];
    }

    public function update()
    {
        $id = (int) $_POST['id'];
        $party = PartyModel::where(['id' => $id])->first();

        if (!$party) {
            return ['error' => 1, 'message' => 'Parti bulunamadı.'];
        }
        if ($party->uid != App::user()->getUid()) {
            return ['error' => 1, 'message' => 'Yetkisiz işlem.'];
        }

        $party->description = trim(strip_tags($_POST['description'] ?? ''));
        $party->ideology = trim(strip_tags($_POST['ideology'] ?? ''));
        $party->economy_stance = trim(strip_tags($_POST['economy_stance'] ?? ''));
        $party->cover_pic = strip_tags($_POST['cover_pic'] ?? '');
        $party->logo_url = trim(strip_tags($_POST['logo_url'] ?? ''));

        if (isset($_POST['daily_fee'])) {
            $feeStr = str_replace(',', '.', $_POST['daily_fee']);
            $party->daily_fee = (float) $feeStr;
        }

        $party->save();
        $this->addLog($id, 'Karargah ayarlari guncellendi.');

        return ['error' => 0, 'message' => 'Ayarlar basariyla kaydedildi.'];
    }

    public function join()
    {
        $id = (int) $_POST['id'];
        $message = trim(strip_tags($_POST['message'] ?? ''));
        $blocked = ActionRateLimiter::throttle(
            'party_join_request',
            'uid:' . (int) App::user()->getUid(),
            8,
            3600,
            7200,
            'Parti basvurulari cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        if (App::user()->getPoliticalParty()) {
            return ['error' => 1, 'message' => 'Zaten bir partidesiniz.'];
        }

        $party = PartyModel::where('id', $id)->first();
        if (!$party) {
            return ['error' => 1, 'message' => 'Parti bulunamadi.'];
        }

        $dailyLimitError = $this->ensurePartyDailyCapacity(
            $id,
            ['join_application'],
            self::PARTY_DAILY_APPLICATION_LIMIT,
            'Bu parti bugun en fazla 10 uyelik basvurusu alabilir. Lutfen yarin tekrar deneyin.'
        );
        if ($dailyLimitError !== null) {
            return $dailyLimitError;
        }

        try {
            $existingPending = DB::table('party_join_applications')
                ->where('uid', App::user()->getUid())
                ->where('status', 'pending')
                ->first();
        } catch (\Exception $e) {
            return ['error' => 1, 'message' => 'Uyelik basvurusu tablosu hazir degil. SQL guncellemesini once calistirin.'];
        }

        if ($existingPending) {
            if ((int) $existingPending->party_id === $id) {
                return ['error' => 1, 'message' => 'Bu parti icin zaten bekleyen bir basvurunuz var.'];
            }

            return ['error' => 1, 'message' => 'Baska bir parti icin bekleyen basvurunuz var.'];
        }

        DB::table('party_join_applications')->insert([
            'party_id' => $id,
            'uid' => App::user()->getUid(),
            'message' => $message,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $this->recordPartyDailyAction($id, App::user()->getUid(), 'join_application', [
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return ['error' => 1, 'message' => 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.'];
        }

        $this->addLog($id, $this->getSafeUserName() . ' uyelik basvurusu gonderdi.');

        try {
            Notify::push(
                (int) $party->uid,
                'party',
                'Yeni uyelik basvurusu',
                $this->getSafeUserName() . ' partinize katilmak icin basvuru gonderdi.',
                '/party/' . $id,
                [
                    'party_id' => $id,
                    'party_name' => (string) $party->name,
                    'action' => 'join_application',
                    'applicant_uid' => (int) App::user()->getUid(),
                    'applicant_name' => $this->getSafeUserName(),
                ]
            );
        } catch (\Throwable $e) {
        }

        return ['error' => 0, 'message' => 'Uyelik basvurunuz parti liderine iletildi.', 'pending' => 1];
    }

    public function leave()
    {
        $blocked = ActionRateLimiter::throttle(
            'party_leave',
            'uid:' . (int) App::user()->getUid(),
            4,
            3600,
            7200,
            'Partiden ayrilma denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $aff = App::user()->getPoliticalParty();
        if ($aff) {
            $id = $aff->party;
            $dailyLimitError = $this->ensurePartyDailyCapacity(
                $id,
                ['leave', 'member_removed'],
                self::PARTY_DAILY_LEAVE_LIMIT,
                'Bu partide bugun en fazla 10 ayrilma veya cikarma islemi yapilabilir. Lutfen yarin tekrar deneyin.'
            );
            if ($dailyLimitError !== null) {
                return $dailyLimitError;
            }
            $this->addLog($id, $this->getSafeUserName() . ' ayrıldı.');
            $aff->delete();
            try {
                $this->recordPartyDailyAction($id, App::user()->getUid(), 'leave');
            } catch (\Exception $e) {
                return ['error' => 1, 'message' => 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.'];
            }
            return ['error' => 0];
        }
        return ['error' => 1];
    }

    public function donate()
    {
        $id = (int) $_POST['id'];
        $amount = (float) $_POST['amount'];
        $blocked = ActionRateLimiter::throttle(
            'party_donate',
            'uid:' . (int) App::user()->getUid(),
            12,
            600,
            900,
            'Bagis denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        if ($amount <= 0) {
            return ['error' => 1, 'message' => 'Geçersiz miktar.'];
        }

        $money = UserMoney::where(['uid' => App::user()->getUid()])->first();
        if (!$money || $money->gold < $amount) {
            return ['error' => 1, 'message' => 'Yetersiz bakiye. Gerekli altınıniz yok.'];
        }

        $party = PartyModel::where(['id' => $id])->first();
        if (!$party) {
            return ['error' => 1, 'message' => 'Parti bulunamadı.'];
        }

        $money->gold -= $amount;
        $money->save();

        $party->treasury += $amount;
        $party->save();

        $this->addLog($id, $this->getSafeUserName() . ' ' . $amount . ' Altın bağışladı.');
        return ['error' => 0, 'result' => (float) $party->treasury];
    }

    public function buyAd()
    {
        $blocked = ActionRateLimiter::throttle(
            'party_buy_ad',
            'uid:' . (int) App::user()->getUid(),
            4,
            3600,
            7200,
            'Reklam kampanyasi denemeleri cok hizlandi. Lutfen biraz bekleyin.'
        );
        if ($blocked !== null) {
            return $blocked;
        }

        $party = PartyModel::where(['id' => (int) $_POST['id']])->first();
        if (!$party) {
            return ['error' => 1, 'message' => 'Parti bulunamadı.'];
        }

        if ($party->uid != App::user()->getUid()) {
            return ['error' => 1, 'message' => 'Sadece parti lideri reklam kampanyasi baslatabilir.'];
        }

        $days = (int) ($_POST['days'] ?? 1);
        $campaign = $this->findAdCampaignOption($days);
        if (!$campaign) {
            return ['error' => 1, 'message' => 'Gecersiz reklam paketi secildi.'];
        }

        if (!empty($party->last_ad_purchase_at)) {
            $nextAdAt = strtotime($party->last_ad_purchase_at . ' +' . self::AD_PURCHASE_COOLDOWN_DAYS . ' days');
            if ($nextAdAt && $nextAdAt > time()) {
                return [
                    'error' => 1,
                    'message' => 'Bu parti 15 gunde sadece 1 kere reklam verebilir. Tekrar tarih: ' . date('d.m.Y H:i', $nextAdAt),
                    'next_available_at' => date('Y-m-d H:i:s', $nextAdAt),
                ];
            }
        }

        $cost = (float) $campaign['cost'];
        if ($party->treasury < $cost) {
            return ['error' => 1, 'message' => 'Parti kasasinda yeterli bakiye yok! Gereken: ' . number_format($cost, 2, '.', '') . ' Altin.'];
        }

        $baseTimestamp = time();
        if (!empty($party->ad_until)) {
            $currentEnd = strtotime($party->ad_until);
            if ($currentEnd && $currentEnd > $baseTimestamp) {
                $baseTimestamp = $currentEnd;
            }
        }

        $party->treasury -= $cost;
        $party->ad_until = date('Y-m-d H:i:s', strtotime('+' . $days . ' days', $baseTimestamp));
        $party->last_ad_purchase_at = date('Y-m-d H:i:s');
        $party->save();

        $this->addLog(
            $party->id,
            'Sponsorlu reklam kampanyasi baslatildi: ' . $campaign['label'] . ' / ' . number_format($cost, 0, '.', '') . ' Altin.'
        );

        return [
            'error' => 0,
            'cost' => $cost,
            'days' => $days,
            'ends_at' => $party->ad_until,
        ];
    }

    public function assignRole()
    {
        $member = PartyMember::where([
            'party' => (int) $_POST['party_id'],
            'uid' => (int) $_POST['target_uid'],
        ])->first();

        if ($member) {
            $member->level = (int) $_POST['new_role'];
            $member->save();
            return ['error' => 0];
        }

        return ['error' => 1];
    }

    public function removeMember()
    {
        $partyId = (int) ($_POST['party_id'] ?? 0);
        $targetUid = (int) ($_POST['target_uid'] ?? 0);

        $party = PartyModel::where('id', $partyId)->first();
        if (!$party) {
            return ['error' => 1, 'message' => 'Parti bulunamadi.'];
        }

        if ($party->uid != App::user()->getUid()) {
            return ['error' => 1, 'message' => 'Sadece parti lideri uyeyi cikarabilir.'];
        }

        if ($targetUid === (int) $party->uid) {
            return ['error' => 1, 'message' => 'Parti lideri kendini bu alandan cikaramaz.'];
        }

        $member = PartyMember::where([
            'party' => $partyId,
            'uid' => $targetUid,
        ])->first();

        if (!$member) {
            return ['error' => 1, 'message' => 'Uye zaten partide degil.'];
        }

        $dailyLimitError = $this->ensurePartyDailyCapacity(
            $partyId,
            ['leave', 'member_removed'],
            self::PARTY_DAILY_LEAVE_LIMIT,
            'Bu partide bugun en fazla 10 ayrilma veya cikarma islemi yapilabilir. Lutfen yarin tekrar deneyin.'
        );
        if ($dailyLimitError !== null) {
            return $dailyLimitError;
        }

        $member->delete();

        try {
            $this->recordPartyDailyAction($partyId, $targetUid, 'member_removed', [
                'removed_by' => (int) App::user()->getUid(),
            ]);
        } catch (\Exception $e) {
            return ['error' => 1, 'message' => 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.'];
        }

        $removedName = $this->getJoinApplicationDisplayName($targetUid);
        $this->addLog($partyId, $removedName . ' partiden cikarildi.');

        try {
            Notify::push(
                $targetUid,
                'party',
                'Partiden cikarildiniz',
                $party->name . ' partisinden lider karariyla cikarildiniz.',
                '/party/' . $partyId,
                [
                    'party_id' => $partyId,
                    'party_name' => (string) $party->name,
                    'action' => 'removed',
                ]
            );
        } catch (\Throwable $e) {
        }

        return ['error' => 0, 'message' => 'Uye partiden cikarildi.'];
    }

    public function reviewJoinApplication()
    {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $action = trim((string) ($_POST['action'] ?? ''));

        if (!in_array($action, ['approve', 'reject'], true)) {
            return ['error' => 1, 'message' => 'Gecersiz basvuru islemi.'];
        }

        try {
            $application = DB::table('party_join_applications')
                ->where('id', $applicationId)
                ->where('status', 'pending')
                ->first();
        } catch (\Exception $e) {
            return ['error' => 1, 'message' => 'Uyelik basvurusu tablosu hazir degil. SQL guncellemesini once calistirin.'];
        }

        if (!$application) {
            return ['error' => 1, 'message' => 'Basvuru bulunamadi veya zaten sonuclandi.'];
        }

        $party = PartyModel::where('id', $application->party_id)->first();
        if (!$party || $party->uid != App::user()->getUid()) {
            return ['error' => 1, 'message' => 'Sadece parti lideri basvurulari yonetebilir.'];
        }

        $applicantAffiliation = PartyMember::where('uid', $application->uid)->first();
        if ($action === 'approve' && $applicantAffiliation) {
            DB::table('party_join_applications')
                ->where('id', $applicationId)
                ->update([
                    'status' => 'rejected',
                    'reviewed_by' => App::user()->getUid(),
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return ['error' => 1, 'message' => 'Bu kullanici zaten baska bir partide.'];
        }

        if ($action === 'approve') {
            $dailyLimitError = $this->ensurePartyDailyCapacity(
                (int) $party->id,
                ['join_approved'],
                self::PARTY_DAILY_JOIN_LIMIT,
                'Bu parti bugun en fazla 10 yeni uye kabul edebilir. Lutfen yarin tekrar deneyin.'
            );
            if ($dailyLimitError !== null) {
                return $dailyLimitError;
            }

            PartyMember::create([
                'party' => $party->id,
                'uid' => $application->uid,
                'level' => 0,
            ]);

            try {
                $this->recordPartyDailyAction((int) $party->id, (int) $application->uid, 'join_approved', [
                    'application_id' => $applicationId,
                    'reviewed_by' => (int) App::user()->getUid(),
                ]);
            } catch (\Exception $e) {
                return ['error' => 1, 'message' => 'Parti gunluk limit tablosu hazir degil. SQL guncellemesini once calistirin.'];
            }
        }

        DB::table('party_join_applications')
            ->where('id', $applicationId)
            ->update([
                'status' => $action === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => App::user()->getUid(),
                'reviewed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($action === 'approve') {
            DB::table('party_join_applications')
                ->where('uid', $application->uid)
                ->where('status', 'pending')
                ->where('id', '!=', $applicationId)
                ->update([
                    'status' => 'rejected',
                    'reviewed_by' => App::user()->getUid(),
                    'reviewed_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        $applicantName = $this->getJoinApplicationDisplayName((int) $application->uid);
        $this->addLog(
            $party->id,
            $applicantName . ($action === 'approve' ? ' partiye kabul edildi.' : ' uyelik basvurusu reddedildi.')
        );

        try {
            Notify::push(
                (int) $application->uid,
                'party',
                $action === 'approve' ? 'Uyelik basvurunuz kabul edildi' : 'Uyelik basvurunuz reddedildi',
                $action === 'approve'
                    ? $party->name . ' partisi basvurunuzu onayladi.'
                    : $party->name . ' partisi basvurunuzu reddetti.',
                '/party/' . $party->id,
                [
                    'party_id' => (int) $party->id,
                    'party_name' => (string) $party->name,
                    'application_id' => $applicationId,
                    'result' => $action,
                ]
            );
        } catch (\Throwable $e) {
        }

        return [
            'error' => 0,
            'message' => $action === 'approve' ? 'Basvuru kabul edildi.' : 'Basvuru reddedildi.',
        ];
    }

    public function createCoalition()
    {
        $name = trim(strip_tags($_POST['name'] ?? ''));
        $logo = trim(strip_tags($_POST['logo'] ?? ''));
        $manifesto = trim(strip_tags($_POST['manifesto'] ?? ''));

        if (mb_strlen($name) < 3) {
            return ['error' => 1, 'field' => 'name', 'message' => 'Koalisyon adi en az 3 karakter olmalidir.'];
        }

        if ($manifesto !== '' && mb_strlen($manifesto) < 5) {
            return ['error' => 1, 'field' => 'manifesto', 'message' => 'Manifesto yaziyorsaniz en az 5 karakter olmali.'];
        }

        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) {
            return ['error' => 1, 'message' => 'Bir partiniz yok.'];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid() || !empty($myParty->coalition_id)) {
            return ['error' => 1, 'message' => 'Yetkisiz işlem veya zaten koalisyondasınız.'];
        }

        $existingCoalition = DB::table('coalitions')
            ->where('founder_party_id', $myParty->id)
            ->first();

        if ($existingCoalition) {
            $myParty->coalition_id = (int) $existingCoalition->id;
            $myParty->save();

            return [
                'error' => 0,
                'message' => 'Partinize ait mevcut koalisyon kaydi bulundu ve tekrar baglandi.',
                'relinked' => 1,
            ];
        }

        $nameExists = DB::table('coalitions')
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->exists();

        if ($nameExists) {
            return ['error' => 1, 'field' => 'name', 'message' => 'Bu isimde bir koalisyon zaten var. Farkli bir ad secin.'];
        }

        try {
            $coalitionId = DB::table('coalitions')->insertGetId([
                'name' => $name,
                'logo' => $logo,
                'manifesto' => $manifesto,
                'founder_party_id' => $myParty->id,
                'treasury' => 0,
            ]);
        } catch (\Throwable $e) {
            return ['error' => 1, 'message' => 'Koalisyon kaydi yapilamadi. Lutfen tekrar deneyin.'];
        }

        $myParty->coalition_id = $coalitionId;
        $myParty->save();

        return ['error' => 0, 'message' => 'Koalisyon kuruldu.'];
    }

    public function leaveCoalition()
    {
        $partyId = (int) $_POST['party_id'];
        $myParty = PartyModel::where('id', $partyId)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid() || empty($myParty->coalition_id)) {
            return ['error' => 1];
        }

        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();
        if ($coalition && $coalition->founder_party_id == $myParty->id) {
            PartyModel::where('coalition_id', $coalition->id)->update(['coalition_id' => null]);
            DB::table('coalitions')->where('id', $coalition->id)->delete();
        } else {
            $myParty->coalition_id = null;
            $myParty->save();
        }

        return ['error' => 0];
    }

    public function inviteToCoalition()
    {
        $targetPartyId = (int) $_POST['target_party_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid() || empty($myParty->coalition_id)) {
            return ['error' => 1];
        }

        $targetParty = PartyModel::where('id', $targetPartyId)->first();
        if (!$targetParty || !empty($targetParty->coalition_id)) {
            return ['error' => 1, 'message' => 'Parti zaten bir koalisyonda.'];
        }

        $exists = DB::table('coalition_invites')
            ->where('target_party_id', $targetPartyId)
            ->where('coalition_id', $myParty->coalition_id)
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            return ['error' => 1, 'message' => 'Zaten davet gönderilmiş.'];
        }

        DB::table('coalition_invites')->insert([
            'coalition_id' => $myParty->coalition_id,
            'inviter_party_id' => $myParty->id,
            'target_party_id' => $targetPartyId,
            'status' => 'pending',
        ]);

        return ['error' => 0];
    }

    public function acceptCoalitionInvite()
    {
        $inviteId = (int) $_POST['invite_id'];
        $invite = DB::table('coalition_invites')->where('id', $inviteId)->first();
        if (!$invite || $invite->status != 'pending') {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $invite->target_party_id)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid()) {
            return ['error' => 1];
        }

        $myParty->coalition_id = $invite->coalition_id;
        $myParty->save();
        DB::table('coalition_invites')->where('id', $inviteId)->update(['status' => 'accepted']);

        return ['error' => 0];
    }

    public function rejectCoalitionInvite()
    {
        $inviteId = (int) $_POST['invite_id'];
        $invite = DB::table('coalition_invites')->where('id', $inviteId)->first();
        if (!$invite) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $invite->target_party_id)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid()) {
            return ['error' => 1];
        }

        DB::table('coalition_invites')->where('id', $inviteId)->update(['status' => 'rejected']);
        return ['error' => 0];
    }

    public function donateToCoalition()
    {
        $amount = (float) $_POST['amount'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation || $amount <= 0) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if (!$myParty || $myParty->uid != App::user()->getUid() || empty($myParty->coalition_id)) {
            return ['error' => 1];
        }

        if ($myParty->treasury < $amount) {
            return ['error' => 1, 'message' => 'Parti kasasında yeterli bakiye yok.'];
        }

        $myParty->treasury -= $amount;
        $myParty->save();

        DB::table('coalitions')->where('id', $myParty->coalition_id)->increment('treasury', $amount);
        return ['error' => 0];
    }

    public function distributeCoalitionFund()
    {
        $targetPartyId = (int) $_POST['target_party_id'];
        $amount = (float) $_POST['amount'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation || $amount <= 0) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();

        if (!$coalition || $coalition->founder_party_id != $myParty->id) {
            return ['error' => 1, 'message' => 'Yetkisiz işlem.'];
        }
        if ($coalition->treasury < $amount) {
            return ['error' => 1, 'message' => 'İttifak kasasında yeterli bakiye yok.'];
        }

        $targetParty = PartyModel::where('id', $targetPartyId)
            ->where('coalition_id', $coalition->id)
            ->first();

        if (!$targetParty) {
            return ['error' => 1];
        }

        DB::table('coalitions')->where('id', $coalition->id)->decrement('treasury', $amount);
        $targetParty->treasury += $amount;
        $targetParty->save();

        return ['error' => 0];
    }

    public function addEmbargo()
    {
        $targetPartyId = (int) $_POST['target_party_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();

        if (!$coalition || $coalition->founder_party_id != $myParty->id) {
            return ['error' => 1];
        }
        if ($coalition->treasury < 50) {
            return ['error' => 1, 'message' => 'Koalisyon kasasında yeterli bakiye (50) yok.'];
        }

        DB::table('coalitions')->where('id', $coalition->id)->decrement('treasury', 50);
        DB::table('coalition_embargos')->insert([
            'coalition_id' => $coalition->id,
            'target_party_id' => $targetPartyId,
        ]);

        return ['error' => 0];
    }

    public function removeEmbargo()
    {
        $targetPartyId = (int) $_POST['target_party_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) {
            return ['error' => 1];
        }

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();

        if (!$coalition || $coalition->founder_party_id != $myParty->id) {
            return ['error' => 1];
        }

        DB::table('coalition_embargos')
            ->where('coalition_id', $coalition->id)
            ->where('target_party_id', $targetPartyId)
            ->delete();

        return ['error' => 0];
    }
}

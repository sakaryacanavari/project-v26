<?php

namespace App\Controllers;

use App\System\Controller;
use App\System\App;
use App\System\AppException;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\PoliticalParty as PartyModel;

class Coalition extends Controller
{
    public function showList()
    {
        return $this->render('coalition/list.html.twig', [
            'page_title' => 'Siyasi Koalisyonlar'
        ]);
    }

    public function create()
    {
        $name = trim(strip_tags($_POST['name'] ?? ''));
        $logo = trim(strip_tags($_POST['logo'] ?? ''));
        $manifesto = trim(strip_tags($_POST['manifesto'] ?? ''));

        if (empty($name) || strlen($name) < 3) throw new AppException(AppException::INVALID_DATA);

        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);

        $party = PartyModel::where('id', $myAffiliation->party)->first();
        if ($party->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED); 
        if (!empty($party->coalition_id)) throw new AppException(AppException::ACTION_FAILED); 

        $coalitionId = DB::table('coalitions')->insertGetId([
            'name' => $name, 'logo' => $logo, 'manifesto' => $manifesto,
            'founder_party_id' => $party->id, 'created_at' => date('Y-m-d H:i:s')
        ]);

        $party->coalition_id = $coalitionId;
        $party->save();

        return true; 
    }

    public function leave()
    {
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);

        $party = PartyModel::where('id', $myAffiliation->party)->first();
        if ($party->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED);

        $party->coalition_id = null;
        $party->save();

        return true;
    }

    public function inviteParty()
    {
        $targetPartyId = (int)$_POST['target_party_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        
        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if ($myParty->uid != App::user()->getUid() || empty($myParty->coalition_id)) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $targetParty = PartyModel::where('id', $targetPartyId)->first();
        if (!$targetParty || !empty($targetParty->coalition_id)) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        $exists = DB::table('coalition_invites')
            ->where('coalition_id', $myParty->coalition_id)
            ->where('target_party_id', $targetPartyId)
            ->where('status', 'pending')->exists();
            
        if ($exists) throw new AppException(AppException::ACTION_FAILED);

        DB::table('coalition_invites')->insert([
            'coalition_id' => $myParty->coalition_id,
            'target_party_id' => $targetPartyId,
            'inviter_party_id' => $myParty->id,
            'status' => 'pending'
        ]);

        return true;
    }

    public function acceptInvite()
    {
        $inviteId = (int)$_POST['invite_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        
        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if ($myParty->uid != App::user()->getUid() || !empty($myParty->coalition_id)) throw new AppException(AppException::ACTION_FAILED);

        $invite = DB::table('coalition_invites')->where('id', $inviteId)->where('target_party_id', $myParty->id)->where('status', 'pending')->first();
        if (!$invite) throw new AppException(AppException::ACTION_FAILED);

        DB::table('coalition_invites')->where('id', $inviteId)->update(['status' => 'accepted']);
        $myParty->coalition_id = $invite->coalition_id;
        $myParty->save();

        return true;
    }

    public function rejectInvite()
    {
        $inviteId = (int)$_POST['invite_id'];
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        
        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if ($myParty->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED);

        DB::table('coalition_invites')->where('id', $inviteId)->where('target_party_id', $myParty->id)->update(['status' => 'rejected']);
        return true;
    }

    public function donate()
    {
        $amount = (float)$_POST['amount'];
        if ($amount <= 0) throw new AppException(AppException::INVALID_DATA);

        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);

        $party = PartyModel::where('id', $myAffiliation->party)->first();
        if ($party->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED);
        if (empty($party->coalition_id)) throw new AppException(AppException::ACTION_FAILED);
        if ($party->treasury < $amount) throw new AppException(AppException::NO_ENOUGH_MONEY);

        $party->treasury -= $amount;
        $party->save();

        DB::table('coalitions')->where('id', $party->coalition_id)->increment('treasury', $amount);

        DB::table('party_logs')->insert([
            'party_id' => $party->id,
            'uid' => App::user()->getUid(),
            'message' => "Birlik Kasasına " . $amount . " Altın aktarıldı."
        ]);

        return true;
    }

    public function distributeFund()
    {
        $targetPartyId = (int)$_POST['target_party_id'];
        $amount = (float)$_POST['amount'];
        
        if ($amount <= 0 || $targetPartyId <= 0) throw new AppException(AppException::INVALID_DATA);

        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);

        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if ($myParty->uid != App::user()->getUid()) throw new AppException(AppException::ACTION_FAILED);
        if (empty($myParty->coalition_id)) throw new AppException(AppException::ACTION_FAILED);

        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();
        if (!$coalition || $coalition->founder_party_id != $myParty->id) throw new AppException(AppException::ACTION_FAILED);

        $targetParty = PartyModel::where('id', $targetPartyId)->first();
        if (!$targetParty || $targetParty->coalition_id != $coalition->id) throw new AppException(AppException::ACTION_FAILED);

        if ($coalition->treasury < $amount) throw new AppException(AppException::NO_ENOUGH_MONEY);

        DB::table('coalitions')->where('id', $coalition->id)->decrement('treasury', $amount);
        $targetParty->treasury += $amount;
        $targetParty->save();

        DB::table('party_logs')->insert([
            'party_id' => $targetParty->id,
            'uid' => App::user()->getUid(),
            'message' => "İttifak Merkez Bankası'ndan " . $amount . " Altın Teşvik Fonu alındı."
        ]);

        return true;
    }

    // --- YENİ: SİYASİ AMBARGO FONKSİYONLARI ---
    public function addEmbargo() {
        $targetPartyId = (int)$_POST['target_party_id'];
        
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        
        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if (empty($myParty->coalition_id)) throw new AppException(AppException::ACTION_FAILED);
        
        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();
        if ($coalition->founder_party_id != $myParty->id || $myParty->uid != App::user()->getUid()) {
            throw new AppException(AppException::ACTION_FAILED); // Sadece kurucu ambargo koyabilir
        }
        
        if ($myParty->id == $targetPartyId) throw new AppException(AppException::ACTION_FAILED);

        $targetParty = PartyModel::where('id', $targetPartyId)->first();
        if (!$targetParty || $targetParty->coalition_id == $coalition->id) throw new AppException(AppException::ACTION_FAILED); // Kendi müttefikine ambargo koyamazsın

        // Ambargo Maliyeti: Merkez Bankasından 50 Altın
        if ($coalition->treasury < 50) throw new AppException(AppException::NO_ENOUGH_MONEY);

        $exists = DB::table('coalition_embargos')->where('coalition_id', $coalition->id)->where('target_party_id', $targetPartyId)->exists();
        if ($exists) throw new AppException(AppException::ACTION_FAILED);

        // İşlemler
        DB::table('coalitions')->where('id', $coalition->id)->decrement('treasury', 50);
        DB::table('coalition_embargos')->insert(['coalition_id' => $coalition->id, 'target_party_id' => $targetPartyId]);
        
        DB::table('party_logs')->insert([
            'party_id' => $targetPartyId, 
            'uid' => 0, 
            'message' => "⚠️ " . $coalition->name . " koalisyonu partinize TİCARİ AMBARGO uyguladı!"
        ]);
        
        return true;
    }

    public function removeEmbargo() {
        $targetPartyId = (int)$_POST['target_party_id'];
        
        $myAffiliation = App::user()->getPoliticalParty();
        if (!$myAffiliation) throw new AppException(AppException::ACTION_FAILED);
        
        $myParty = PartyModel::where('id', $myAffiliation->party)->first();
        if (empty($myParty->coalition_id)) throw new AppException(AppException::ACTION_FAILED);
        
        $coalition = DB::table('coalitions')->where('id', $myParty->coalition_id)->first();
        if ($coalition->founder_party_id != $myParty->id || $myParty->uid != App::user()->getUid()) {
            throw new AppException(AppException::ACTION_FAILED);
        }

        DB::table('coalition_embargos')->where('coalition_id', $coalition->id)->where('target_party_id', $targetPartyId)->delete();
        
        DB::table('party_logs')->insert([
            'party_id' => $targetPartyId, 
            'uid' => 0, 
            'message' => "✅ " . $coalition->name . " koalisyonu partiniz üzerindeki ticari ambargoyu kaldırdı."
        ]);
        
        return true;
    }
}
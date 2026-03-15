<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Siyasi parti controller'ı.
 */
class PoliticalParty extends Controller
{
    public function showList()
    {
        $parties = DB::table('parties')->orderBy('name')->get();
        return $this->render('party/list.html.twig', ['parties' => $parties]);
    }

    public function showCreationForm()
    {
        return $this->render('party/create.html.twig');
    }

    public function showParty($id)
    {
        $party = DB::table('parties')->where('id', $id)->first();
        return $this->render('party/party.html.twig', ['party' => $party]);
    }

    public function create() { return $this->error('Parti sistemi yakında aktif.'); }
    public function update() { return $this->error('Parti sistemi yakında aktif.'); }
    public function join() { return $this->error('Parti sistemi yakında aktif.'); }
    public function leave() { return $this->error('Parti sistemi yakında aktif.'); }
    public function donate() { return $this->error('Parti sistemi yakında aktif.'); }
    public function buyAd() { return $this->error('Parti sistemi yakında aktif.'); }
    public function assignRole() { return $this->error('Parti sistemi yakında aktif.'); }
    public function createCoalition() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function leaveCoalition() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function inviteToCoalition() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function acceptCoalitionInvite() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function rejectCoalitionInvite() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function donateToCoalition() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function distributeCoalitionFund() { return $this->error('Koalisyon sistemi yakında aktif.'); }
    public function addEmbargo() { return $this->error('Ambargo sistemi yakında aktif.'); }
    public function removeEmbargo() { return $this->error('Ambargo sistemi yakında aktif.'); }
}

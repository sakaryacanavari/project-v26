<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Kongre controller'ı.
 */
class Congress extends Controller
{
    public function showHome()
    {
        $uid = $this->uid();

        $user = DB::table('users')
            ->join('regions', 'users.region', '=', 'regions.id')
            ->select('users.*', 'regions.country_id')
            ->where('users.id', $uid)
            ->first();

        $laws = DB::table('law_proposals')
            ->where('country', $user->country_id ?? 0)
            ->where('finished', 0)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->render('congress/home.html.twig', [
            'laws' => $laws,
            'user' => $user,
        ]);
    }

    public function showLawProposal($id)
    {
        $law = DB::table('law_proposals')->where('id', $id)->first();
        if (!$law) {
            return $this->render('congress/access_restricted.html.twig');
        }
        return $this->render('congress/law.html.twig', ['law' => $law]);
    }

    public function proposeLaw()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }

    public function voteLaw()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }

    public function presidentialVeto()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }

    public function callEmergency()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }

    public function setWhip()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }

    public function revokeState()
    {
        return $this->error('Kongre sistemi yakında aktif olacak.');
    }
}

<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Savaş controller'ı.
 */
class War extends Controller
{
    public function showList()
    {
        $wars = DB::table('country_relations')
            ->where('relation', 'war')
            ->get();

        return $this->render('war/wars.html.twig', ['wars' => $wars]);
    }

    public function fight()
    {
        return $this->error('Savaş sistemi yakında aktif olacak.');
    }
}

<?php

namespace App\Controllers;

use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Dünya haritası controller'ı.
 */
class WorldMap extends Controller
{
    public function showMap()
    {
        $countries = DB::table('countries')
            ->join('regions', 'countries.id', '=', 'regions.country_id')
            ->select('countries.*')
            ->distinct()
            ->get();

        return $this->render('war/map.html.twig', ['countries' => $countries]);
    }
}

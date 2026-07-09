<?php
namespace App\Controllers;

use App\System\App;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class WorldMap extends Controller
{
    public function showMap()
    {
        // 1. TÜM ÜLKELERİ ÇEK
        $countries = DB::table('countries')->get();
        $presidentIds = [];
        $countriesById = [];
        
        foreach ($countries as $c) {
            $countriesById[$c->id] = $c;
            if ($c->president > 0) {
                $presidentIds[] = $c->president;
            }
        }

        // BAŞKANLARI BUL
        $presidents = [];
        if (!empty($presidentIds)) {
            $presidentUsers = DB::table('users')->whereIn('id', array_unique($presidentIds))->get();
            foreach ($presidentUsers as $user) {
                $presidents[$user->id] = $user->nick;
            }
        }

        // ÜLKE VERİLERİNİ HAZIRLA
        $countryData = [];
        foreach ($countries as $c) {
            $countryData[mb_strtolower($c->name, 'UTF-8')] = [
                'id'           => $c->id,
                'name'         => $c->name,
                'color'        => $c->color ?? '#d1d5db', 
                'president'    => $presidents[$c->president] ?? "Atama Bekleniyor",
                'currency'     => strtoupper($c->currency ?? 'BİLİNMİYOR'),
                'minimum_wage' => $c->minimum_wage ?? 0
            ];
        }

        // 2. TÜM BÖLGELERİ ÇEK
        $allRegions = DB::table('regions')->get();
        $regionData = [];

        foreach ($allRegions as $region) {
            $country = $countriesById[$region->country] ?? null;
            $safeRegionName = mb_strtolower($region->name, 'UTF-8');

            $regionData[$safeRegionName] = [
                'region_id'    => $region->id,
                'region_name'  => $region->name,
                'country_id'   => $country ? $country->id : 0,
                'country_name' => $country ? $country->name : 'Bağımsız',
                'color'        => $country ? ($country->color ?? '#d1d5db') : '#d1d5db',
                'president'    => $country ? ($presidents[$country->president] ?? "Atama Bekleniyor") : "Atama Bekleniyor",
                'currency'     => $country ? strtoupper($country->currency ?? 'BİLİNMİYOR') : 'BİLİNMİYOR',
                'minimum_wage' => $country ? $country->minimum_wage : 0
            ];
        }

        return $this->render('map/index.html.twig', [
            "page_title"      => "Küresel Harekat Haritası",
            "countryDataJson" => json_encode($countryData), // Ülke datası
            "regionDataJson"  => json_encode($regionData),  // Bölge datası
        ]);
    }
}
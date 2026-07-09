<?php

namespace App\Controllers;

use App\System\App;
use App\System\Controller;
use App\System\Input;
use Illuminate\Database\Capsule\Manager as DB;

class War extends Controller
{
    private function checkAndResolveRounds()
    {
        $expiredWars = DB::table('wars')
            ->where('status', 'active')
            ->where('ends_at', '<=', date('Y-m-d H:i:s'))
            ->get();

        foreach ($expiredWars as $w) {
            $attackerWins = $w->attacker_damage > $w->defender_damage;
            $atkRounds = isset($w->atk_rounds) ? (int)$w->atk_rounds : 0;
            $defRounds = isset($w->def_rounds) ? (int)$w->def_rounds : 0;

            if ($attackerWins) { $atkRounds++; } else { $defRounds++; }

            if ($atkRounds >= 5 || $defRounds >= 5) {
                DB::table('wars')->where('id', $w->id)->update([
                    'status' => 'finished',
                    'atk_rounds' => $atkRounds,
                    'def_rounds' => $defRounds
                ]);

                if ($atkRounds >= 5 && isset($w->target_region_id) && $w->target_region_id > 0) {
                    DB::table('regions')->where('id', $w->target_region_id)->update(['owner_country_id' => $w->attacker_id]);
                }
            } else {
                $nextRoundTime = date('Y-m-d H:i:s', strtotime($w->ends_at . ' +1 hour'));
                if(strtotime($nextRoundTime) < time()) {
                    $nextRoundTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
                }

                DB::table('wars')->where('id', $w->id)->update([
                    'atk_rounds' => $atkRounds,
                    'def_rounds' => $defRounds,
                    'attacker_damage' => 0,
                    'defender_damage' => 0,
                    'ends_at' => $nextRoundTime
                ]);

                DB::table('war_damages')->where('war_id', $w->id)->delete();
            }
        }
    }

    public function showList()
    {
        $this->checkAndResolveRounds();

        $wars = DB::table('wars')->where('status', 'active')->orderBy('ends_at', 'ASC')->get();
        $uid = App::user()->getUid();
        $user = DB::table('users')->where('id', $uid)->first();

        // KULLANICI ENERJİSİ
        $energyColumn = isset($user->energy) ? 'energy' : (isset($user->health) ? 'health' : 'stamina');
        $myEnergy = $user->$energyColumn ?? 0;
        
        // KULLANICI KONUMU (Region ID)
       $playerRegionId = $user->region; // EĞER SÜTUNUN ADI 'region' İSE BURAYA ONU YAZ

        $myWeapons = DB::table('user_weapons')->where('uid', $uid)->where('quantity', '>', 0)->orderBy('quality', 'ASC')->get();

        $warList = [];
        foreach($wars as $w) {
            $attacker = DB::table('countries')->where('id', $w->attacker_id)->first();
            $defender = DB::table('countries')->where('id', $w->defender_id)->first();
            
            $warRegionId = isset($w->target_region_id) ? $w->target_region_id : 0;
            $region = DB::table('regions')->where('id', $warRegionId)->first();

            $attAlliesIds = DB::table('country_alliances')->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->where(function($q) use ($w) { $q->where('country_1', $w->attacker_id)->orWhere('country_2', $w->attacker_id); })->get()
                ->map(function($al) use ($w) { return $al->country_1 == $w->attacker_id ? $al->country_2 : $al->country_1; })->toArray();
            $attackerAllies = !empty($attAlliesIds) ? DB::table('countries')->whereIn('id', $attAlliesIds)->select('name')->get() : [];

            $defAlliesIds = DB::table('country_alliances')->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->where(function($q) use ($w) { $q->where('country_1', $w->defender_id)->orWhere('country_2', $w->defender_id); })->get()
                ->map(function($al) use ($w) { return $al->country_1 == $w->defender_id ? $al->country_2 : $al->country_1; })->toArray();
            $defenderAllies = !empty($defAlliesIds) ? DB::table('countries')->whereIn('id', $defAlliesIds)->select('name')->get() : [];

            $totalDamage = $w->attacker_damage + $w->defender_damage;
            $atkPct = $totalDamage > 0 ? ($w->attacker_damage / $totalDamage) * 100 : 50;
            $defPct = $totalDamage > 0 ? ($w->defender_damage / $totalDamage) * 100 : 50;

            $attackerHeroes = DB::table('war_damages')->join('users', 'war_damages.uid', '=', 'users.id')->select('users.nick', 'war_damages.damage')->where('war_damages.war_id', $w->id)->where('war_damages.side', 'attacker')->orderBy('war_damages.damage', 'DESC')->limit(5)->get();
            $defenderHeroes = DB::table('war_damages')->join('users', 'war_damages.uid', '=', 'users.id')->select('users.nick', 'war_damages.damage')->where('war_damages.war_id', $w->id)->where('war_damages.side', 'defender')->orderBy('war_damages.damage', 'DESC')->limit(5)->get();

            $myDamageInThisWar = DB::table('war_damages')->where('war_id', $w->id)->where('uid', $uid)->sum('damage');

            $warList[] = [
                'id' => $w->id,
                'region_id' => $warRegionId,
                'player_in_region' => ($playerRegionId == $warRegionId), // GERÇEK ZAMANLI KONUM KONTROLÜ
                'attacker_id' => $w->attacker_id, 'defender_id' => $w->defender_id,
                'attacker_name' => $attacker ? $attacker->name : "Bilinmeyen",
                'defender_name' => $defender ? $defender->name : "Bilinmeyen",
                'attacker_allies' => $attackerAllies, 'defender_allies' => $defenderAllies,
                'region_name' => $region ? $region->name : "Bilinmeyen Bölge", 
                'attacker_damage' => $w->attacker_damage, 'defender_damage' => $w->defender_damage,
                'atk_rounds' => isset($w->atk_rounds) ? $w->atk_rounds : 0,
                'def_rounds' => isset($w->def_rounds) ? $w->def_rounds : 0,
                'atk_percent' => round($atkPct, 1), 'def_percent' => round($defPct, 1),
                'ends_at_timestamp' => strtotime($w->ends_at) * 1000, 
                'attacker_heroes' => $attackerHeroes, 'defender_heroes' => $defenderHeroes,
                'my_damage' => $myDamageInThisWar 
            ];
        }

        return $this->render('wars/list.html.twig', [
            "page_title" => "Aktif Cepheler",
            "wars" => $warList,
            "myWeapons" => $myWeapons,
            "myEnergy" => $myEnergy 
        ]);
    }

    public function fight() 
    {
        try {
            $this->checkAndResolveRounds();

            $warId = Input::getInteger('war_id');
            $side = Input::getString('side'); 
            $weaponQ = Input::getInteger('weapon_q'); 
            $requestedHits = Input::getInteger('hit_count', 1); 
            $uid = App::user()->getUid();

            if (!in_array($side, ['attacker', 'defender'])) return ["error" => true, "message" => "Geçersiz cephe hattı."];
            if ($requestedHits < 1) $requestedHits = 1;

            $war = DB::table('wars')->where('id', $warId)->where('status', 'active')->first();
            if (!$war) return ["error" => true, "message" => "Bu savaş sona erdi veya aktif değil."];

			$user = DB::table('users')->where('id', $uid)->first();
			$playerRegionId = $user->region ?? 0; // Örn: $user->region ?? 0;
            $warRegionId = isset($war->target_region_id) ? $war->target_region_id : 0;
            if ($playerRegionId != $warRegionId) {
                return ["error" => true, "message" => "Savaşmak için cepheye intikal etmelisiniz!"];
            }

            $energyColumn = isset($user->energy) ? 'energy' : (isset($user->health) ? 'health' : 'stamina');
            
            $possibleHitsByEnergy = floor($user->$energyColumn / 10);
            if ($possibleHitsByEnergy < 1) return ["error" => true, "message" => "Savaşmak için en az 10 enerjiye ihtiyacın var!"];
            
            $actualHits = min($requestedHits, $possibleHitsByEnergy);

            $weaponMultiplier = 1;
            $weaponRecord = null;
            if ($weaponQ > 0) {
                $weaponRecord = DB::table('user_weapons')->where('uid', $uid)->where('quality', $weaponQ)->where('quantity', '>', 0)->first();
                if (!$weaponRecord) return ["error" => true, "message" => "Seçtiğin Q{$weaponQ} silah envanterinde yok!"];
                
                $actualHits = min($actualHits, $weaponRecord->quantity);
                if ($actualHits < 1) return ["error" => true, "message" => "Bu silahın tükendi!"];

                $weaponMultiplier = 1 + ($weaponQ * 0.4); 
            }

            $userStrength = isset($user->strength) ? $user->strength : 10;
            $totalDamageDealt = 0;
            $criticalCount = 0;

            for ($i = 0; $i < $actualHits; $i++) {
                $baseDamage = rand(10, 20) * $userStrength; 
                $dmg = $baseDamage * $weaponMultiplier;
                if (rand(1, 100) <= 15) { 
                    $criticalCount++;
                    $dmg *= 2; 
                }
                $totalDamageDealt += round($dmg);
            }

            DB::beginTransaction();
            
            DB::table('users')->where('id', $uid)->decrement($energyColumn, $actualHits * 10);
            if ($weaponQ > 0 && $weaponRecord) {
                DB::table('user_weapons')->where('id', $weaponRecord->id)->decrement('quantity', $actualHits);
            }
            
            $damageColumn = $side . '_damage';
            DB::table('wars')->where('id', $warId)->increment($damageColumn, $totalDamageDealt);

            $existingRecord = DB::table('war_damages')->where('war_id', $warId)->where('uid', $uid)->where('side', $side)->first();
            if ($existingRecord) { 
                DB::table('war_damages')->where('id', $existingRecord->id)->increment('damage', $totalDamageDealt); 
            } else { 
                DB::table('war_damages')->insert(['war_id' => $warId, 'uid' => $uid, 'side' => $side, 'damage' => $totalDamageDealt]); 
            }

            $updatedWar = DB::table('wars')->where('id', $warId)->first();
            $totalDmg = $updatedWar->attacker_damage + $updatedWar->defender_damage;
            $newAttPct = $totalDmg > 0 ? round(($updatedWar->attacker_damage / $totalDmg) * 100, 1) : 50;
            $newDefPct = $totalDmg > 0 ? round(($updatedWar->defender_damage / $totalDmg) * 100, 1) : 50;

            DB::commit();

            return [
                "success" => true,
                "damage" => $totalDamageDealt,
                "hits" => $actualHits,
                "critical_hits" => $criticalCount,
                "attacker_pct" => $newAttPct,
                "defender_pct" => $newDefPct
            ];

        } catch (\Exception $e) {
            if (DB::getPdo()->inTransaction()) { DB::rollBack(); }
            return ["error" => true, "message" => "Karargah Hatası: " . $e->getMessage()];
        }
    }

    // --- SEYAHAT (İNTİKAL) FONKSİYONU ---
    public function travel()
    {
        try {
            $regionId = Input::getInteger('region_id');
            $uid = App::user()->getUid();

            if (!$regionId) {
                return ["error" => true, "message" => "Geçersiz hedef koordinatları."];
            }

            $user = DB::table('users')->where('id', $uid)->first();
            $locColumn = 'region'; // BURAYA VERİTABANINDAKİ GERÇEK SÜTUN ADINI YAZ
			$playerRegionId = $user->$locColumn ?? 0;

            if ($playerRegionId == $regionId) {
                return ["error" => true, "message" => "Zaten bu cephede bulunuyorsunuz."];
            }

            // Geliştirme Notu: İleride kullanıcının biletini (moving ticket) düşmek istersen kodu buraya yazabilirsin.
            // Örnek: DB::table('user_items')->where('uid', $uid)->where('item', 'ticket')->decrement('quantity', 1);

            DB::table('users')->where('id', $uid)->update([$locColumn => $regionId]);

            return ["success" => true, "message" => "Hedef bölgeye başarıyla intikal edildi."];

        } catch (\Exception $e) {
            return ["error" => true, "message" => "İntikal Hatası: " . $e->getMessage()];
        }
    }
}
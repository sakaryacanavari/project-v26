<?php

namespace App\Controllers;

use App\Models\UserGym;
use App\System\App;
use App\System\Controller;
use App\System\Input;
use Illuminate\Database\Capsule\Manager as DB;

class Gym extends Controller
{
    public function train()
    {
        try {
            $quality = Input::getInteger('quality');
            $uid = (int) App::user()->getUid();

            $gymStats = [
                1 => ['strength' => 5, 'energy' => 10, 'cost' => 0],
                2 => ['strength' => 10, 'energy' => 10, 'cost' => 0.19],
                3 => ['strength' => 15, 'energy' => 10, 'cost' => 0.89],
                4 => ['strength' => 20, 'energy' => 10, 'cost' => 1.79],
            ];

            if (!isset($gymStats[$quality])) {
                return ["error" => true, "message" => "Gecersiz egitim modulu tespit edildi."];
            }

            $stat = $gymStats[$quality];
            $user = DB::table('users')->where('id', $uid)->first();

            if (empty($user)) {
                return ["error" => true, "message" => "Kullanici kaydi bulunamadi."];
            }

            $gyms = UserGym::where(['uid' => $uid])->first();
            if (empty($gyms)) {
                $gyms = UserGym::create([
                    'uid' => $uid
                ]);
            }

            if (UserGym::hasTrainingTodayForUser($uid, $gyms)) {
                return ["error" => true, "message" => "Bugun sadece 1 egitim yapabilirsiniz."];
            }

            $energyColumn = isset($user->energy) ? 'energy' : 'health';
            if ((float) ($user->{$energyColumn} ?? 0) < (float) $stat['energy']) {
                return ["error" => true, "message" => "Bu egitim icin yeterli enerjiniz yok."];
            }

            DB::beginTransaction();

            if ((float) $stat['cost'] > 0) {
                App::user()->buy($stat['cost'], 'gold', 'TRAIN_Q' . $quality);
            }

            DB::table('users')->where('id', $uid)->decrement($energyColumn, $stat['energy']);
            DB::table('users')->where('id', $uid)->increment('strength', $stat['strength']);

            $gyms['q' . $quality] = date('Y-m-d');
            $gyms->save();

            if (DB::getSchemaBuilder()->hasTable('user_trainings')) {
                DB::table('user_trainings')->insert([
                    'uid' => $uid,
                    'quality' => $quality,
                    'strength_gained' => $stat['strength'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }

            DB::commit();

            return ["success" => true, "message" => "Egitim tamamlandi. +" . $stat['strength'] . " guc kazandiniz."];
        } catch (\Exception $e) {
            if (DB::getPdo()->inTransaction()) {
                DB::rollBack();
            }

            return ["error" => true, "message" => "Egitim hatasi: " . $e->getMessage()];
        }
    }
}

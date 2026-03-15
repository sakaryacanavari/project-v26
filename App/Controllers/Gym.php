<?php

namespace App\Controllers;

use \App\System\App;
use \Illuminate\Database\Capsule\Manager as DB;

/**
 * Spor salonu controller'ı.
 * Günlük antrenman işlemleri.
 */
class Gym extends Controller
{
    /**
     * Antrenman yapar (POST /api/gym/train).
     * Günlük 1 kez antrenman yapılabilir.
     */
    public function train()
    {
        $uid    = $this->uid();
        $gymId  = (int) $this->input('gym', 1); // 1-4 arası spor salonu kalitesi

        if ($gymId < 1 || $gymId > 4) {
            return $this->error('Geçersiz spor salonu.');
        }

        $gymColumn = 'q' . $gymId;
        $gymData   = DB::table('user_gyms')->where('uid', $uid)->first();

        if (!$gymData) {
            // İlk kez kayıt oluştur
            DB::table('user_gyms')->insert(['uid' => $uid]);
            $gymData = DB::table('user_gyms')->where('uid', $uid)->first();
        }

        // Günlük limit kontrolü
        $lastTrainDate = $gymData ? date('Y-m-d', strtotime($gymData->$gymColumn ?? '2000-01-01')) : '2000-01-01';
        if ($lastTrainDate === date('Y-m-d')) {
            return $this->error('Bu spor salonunda bugün zaten antrenman yaptınız.');
        }

        // Güç kazancı
        $strengthGain = $gymId;

        DB::table('users')
            ->where('id', $uid)
            ->increment('strength', $strengthGain);

        DB::table('user_gyms')
            ->where('uid', $uid)
            ->update([
                $gymColumn   => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->success(
            sprintf('Antrenman tamamlandı! +%d güç kazandınız.', $strengthGain)
        );
    }
}

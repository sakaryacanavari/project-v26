<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;

class UserGym extends Model
{
    protected $primaryKey = "uid";
    protected $fillable = ["uid", "q1", "q2", "q3", "q4"];

    public $timestamps = false;

    public static $data = [
        "q1" => [
            "cost" => 0,
            "energy" => 10,
            "strength" => 5,
        ],
        "q2" => [
            "cost" => 5,
            "energy" => 15,
            "strength" => 10,
        ],
        "q3" => [
            "cost" => 10,
            "energy" => 20,
            "strength" => 15,
        ],
        "q4" => [
            "cost" => 15,
            "energy" => 25,
            "strength" => 20,
        ]
    ];

    public function hasTrainedToday ($quality)
    {
        $lastTime = date("Y-m-d", strtotime($this["q$quality"]));

        if ($lastTime == date("Y-m-d")) {
            return true;
        }

        return false;
    }

    public function hasAnyTrainingToday()
    {
        foreach ([1, 2, 3, 4] as $quality) {
            $lastRaw = $this["q$quality"] ?? null;
            if (!empty($lastRaw) && date("Y-m-d", strtotime($lastRaw)) === date("Y-m-d")) {
                return true;
            }
        }

        return false;
    }

    public static function hasTrainingTodayForUser($uid, $model = null)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return false;
        }

        if ($model instanceof self && $model->hasAnyTrainingToday()) {
            return true;
        }

        if (!$model instanceof self) {
            $model = self::where(['uid' => $uid])->first();
            if ($model && $model->hasAnyTrainingToday()) {
                return true;
            }
        }

        if (!DB::getSchemaBuilder()->hasTable('user_trainings')) {
            return false;
        }

        $today = date('Y-m-d');
        return DB::table('user_trainings')
            ->where('uid', $uid)
            ->whereRaw('DATE(created_at) = ?', [$today])
            ->exists();
    }

    public static function getTodayTrainingQualityForUser($uid, $model = null)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return 0;
        }

        if (!$model instanceof self) {
            $model = self::where(['uid' => $uid])->first();
        }

        if ($model instanceof self) {
            foreach ([1, 2, 3, 4] as $quality) {
                $lastRaw = $model["q$quality"] ?? null;
                if (!empty($lastRaw) && date("Y-m-d", strtotime($lastRaw)) === date("Y-m-d")) {
                    return $quality;
                }
            }
        }

        if (!DB::getSchemaBuilder()->hasTable('user_trainings')) {
            return 0;
        }

        $row = DB::table('user_trainings')
            ->where('uid', $uid)
            ->whereRaw('DATE(created_at) = ?', [date('Y-m-d')])
            ->orderBy('created_at', 'desc')
            ->first();

        return (int) ($row->quality ?? 0);
    }
}

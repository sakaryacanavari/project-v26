<?php

namespace App\Controllers;

use App\Models\UserGym;
use App\System\App;
use App\System\Controller;
use Illuminate\Database\Capsule\Manager as DB;

class Gym extends Controller
{
    private const EXTRA_ACTION = 'extra_training';
    private const WHEEL_ACTION = 'reward_wheel';
    private const EXTRA_COST = 5;
    private const EXTRA_STRENGTH_GAIN = 2;

    private const WHEEL_REWARDS = [
        ['key' => 'gold_small', 'type' => 'gold', 'amount' => 2, 'weight' => 45, 'label' => '+2 Gold'],
        ['key' => 'esp_small', 'type' => 'esp', 'amount' => 25, 'weight' => 35, 'label' => '+25 ESP'],
        ['key' => 'strength_small', 'type' => 'strength', 'amount' => 1, 'weight' => 20, 'label' => '+1 Guc'],
    ];

    public function train()
    {
        try {
            $uid = (int) App::user()->getUid();
            $strengthGain = (int) (UserGym::$data['q1']['strength'] ?? 5);
            $dailyActionsReady = UserGym::ensureDailyActionsTable();

            return DB::transaction(function () use ($uid, $strengthGain, $dailyActionsReady) {
                $actionDay = UserGym::serverDay();
                $createdAt = date('Y-m-d H:i:s');
                $user = DB::table('users')->where('id', $uid)->lockForUpdate()->first();
                if (empty($user)) {
                    return ["error" => true, "message" => "Kullanici kaydi bulunamadi."];
                }

                $gyms = UserGym::where(['uid' => $uid])->lockForUpdate()->first();
                if (empty($gyms)) {
                    DB::table('user_gyms')->insert(['uid' => $uid]);
                    $gyms = UserGym::where(['uid' => $uid])->lockForUpdate()->first();
                }

                if (UserGym::hasTrainingQualityToday($uid, 1, $gyms, $actionDay)
                    || ($dailyActionsReady && UserGym::hasDailyActionToday($uid, 'free_training', $actionDay))) {
                    return ["error" => true, "message" => "Günlük eğitim hakkı zaten kullanıldı."];
                }

                $newStrength = (int) ($user->strength ?? 0) + $strengthGain;
                DB::table('users')->where('id', $uid)->increment('strength', $strengthGain);
                $gyms->q1 = $actionDay;
                $gyms->save();

                if (DB::getSchemaBuilder()->hasTable('user_trainings')) {
                    DB::table('user_trainings')->insert([
                        'uid' => $uid,
                        'quality' => 1,
                        'strength_gained' => $strengthGain,
                        'created_at' => $createdAt
                    ]);
                }

                if ($dailyActionsReady) {
                    $action = [
                        'uid' => $uid,
                        'action' => 'free_training',
                        'reward_key' => 'daily_training',
                        'reward_type' => 'strength',
                        'reward_amount' => $strengthGain,
                        'action_day' => $actionDay,
                        'created_at' => $createdAt,
                    ];
                    if (UserGym::hasDailyActionsColumn('strength_after')) {
                        $action['strength_after'] = $newStrength;
                    }
                    DB::table('user_gym_daily_actions')->insert($action);
                }

                App::session()->setUserField('strength', $newStrength);

                return [
                    "success" => true,
                    "message" => "Gunluk egitim tamamlandi.",
                    "strengthGain" => $strengthGain,
                    "newStrength" => $newStrength,
                    "completed" => true,
                    "trainingStreak" => UserGym::getDailyTrainingStreak($uid, $gyms, $actionDay),
                    "resetAt" => date('c', strtotime('tomorrow')),
                    "serverNow" => date('c'),
                ];
            });
        } catch (\Exception $e) {
            return ["error" => true, "message" => "Egitim tamamlanamadi. Lutfen tekrar deneyin."];
        }
    }

    public function extraTrain()
    {
        if (!UserGym::ensureDailyActionsTable()) {
            return ["error" => true, "message" => "Ek egitim su anda kullanilamiyor."];
        }

        try {
            $uid = (int) App::user()->getUid();

            return DB::transaction(function () use ($uid) {
                $actionDay = UserGym::serverDay();
                $createdAt = date('Y-m-d H:i:s');
                $user = DB::table('users')->where('id', $uid)->lockForUpdate()->first();
                if (empty($user)) {
                    return ["error" => true, "message" => "Kullanici kaydi bulunamadi."];
                }

                $gyms = UserGym::where(['uid' => $uid])->lockForUpdate()->first();
                if (UserGym::hasTrainingQualityToday($uid, 2, $gyms, $actionDay)
                    || UserGym::hasDailyActionToday($uid, self::EXTRA_ACTION, $actionDay)) {
                    return [
                        "error" => true,
                        "alreadyUsed" => true,
                        "message" => "Ek eğitim hakkı zaten kullanıldı.",
                    ];
                }

                $wallet = DB::table('user_money')->where('uid', $uid)->lockForUpdate()->first();
                if (empty($wallet)) {
                    return ["error" => true, "message" => "Ek egitim icin bakiye bilgisi bulunamadi."];
                }

                if ((float) ($wallet->gold ?? 0) < self::EXTRA_COST) {
                    return ["error" => true, "message" => "Ek egitim icin yeterli Gold bakiyeniz yok."];
                }

                $charged = DB::table('user_money')
                    ->where('uid', $uid)
                    ->where('gold', '>=', self::EXTRA_COST)
                    ->decrement('gold', self::EXTRA_COST);
                if ($charged !== 1) {
                    return ["error" => true, "message" => "Bakiye guncellenemedi. Lutfen tekrar deneyin."];
                }

                $newStrength = (int) ($user->strength ?? 0) + self::EXTRA_STRENGTH_GAIN;
                DB::table('users')->where('id', $uid)->increment('strength', self::EXTRA_STRENGTH_GAIN);
                $action = [
                    'uid' => $uid,
                    'action' => self::EXTRA_ACTION,
                    'reward_key' => 'extra_strength',
                    'reward_type' => 'strength',
                    'reward_amount' => self::EXTRA_STRENGTH_GAIN,
                    'action_day' => $actionDay,
                    'created_at' => $createdAt,
                ];
                if (UserGym::hasDailyActionsColumn('strength_after')) {
                    $action['strength_after'] = $newStrength;
                }
                DB::table('user_gym_daily_actions')->insert($action);
                App::session()->setUserField('strength', $newStrength);

                return [
                    "success" => true,
                    "message" => "Ek egitim tamamlandi. +" . self::EXTRA_STRENGTH_GAIN . " Guc kazandiniz.",
                ];
            });
        } catch (\Exception $e) {
            return ["error" => true, "message" => "Ek egitim tamamlanamadi. Lutfen tekrar deneyin."];
        }
    }

    public function spinWheel()
    {
        if (!UserGym::ensureDailyActionsTable()) {
            return ["error" => true, "message" => "Gunluk cark su anda kullanilamiyor."];
        }

        try {
            $uid = (int) App::user()->getUid();

            return DB::transaction(function () use ($uid) {
                $actionDay = UserGym::serverDay();
                $createdAt = date('Y-m-d H:i:s');
                $user = DB::table('users')->where('id', $uid)->lockForUpdate()->first();
                if (empty($user)) {
                    return ["error" => true, "message" => "Kullanici kaydi bulunamadi."];
                }

                if (UserGym::hasDailyActionToday($uid, self::WHEEL_ACTION, $actionDay)) {
                    return [
                        "error" => true,
                        "alreadyUsed" => true,
                        "message" => "Günlük çark hakkı zaten kullanıldı.",
                    ];
                }

                $reward = $this->pickWheelReward();
                $wallet = null;
                if (in_array($reward['type'], ['gold', 'esp'], true)) {
                    $wallet = DB::table('user_money')->where('uid', $uid)->lockForUpdate()->first();
                    if (empty($wallet)) {
                        return ["error" => true, "message" => "Odul hesabiniza uygulanamadi."];
                    }

                    DB::table('user_money')->where('uid', $uid)->increment($reward['type'], $reward['amount']);
                } else {
                    DB::table('users')->where('id', $uid)->increment('strength', $reward['amount']);
                }

                $action = [
                    'uid' => $uid,
                    'action' => self::WHEEL_ACTION,
                    'reward_key' => $reward['key'],
                    'reward_type' => $reward['type'],
                    'reward_amount' => $reward['amount'],
                    'action_day' => $actionDay,
                    'created_at' => $createdAt,
                ];
                $newStrength = null;
                if ($reward['type'] === 'strength') {
                    $newStrength = (int) ($user->strength ?? 0) + (int) $reward['amount'];
                    if (UserGym::hasDailyActionsColumn('strength_after')) {
                        $action['strength_after'] = $newStrength;
                    }
                }
                DB::table('user_gym_daily_actions')->insert($action);
                if ($newStrength !== null) {
                    App::session()->setUserField('strength', $newStrength);
                }

                return [
                    "success" => true,
                    "reward" => $reward['label'],
                    "message" => "Gunluk cark odulunuz uygulandi: " . $reward['label'],
                ];
            });
        } catch (\Exception $e) {
            return ["error" => true, "message" => "Gunluk cark tamamlanamadi. Lutfen tekrar deneyin."];
        }
    }

    public function status()
    {
        try {
            $uid = (int) App::user()->getUid();
            $actionDay = UserGym::serverDay();
            $user = DB::table('users')->where('id', $uid)->first(['strength']);
            if (empty($user)) {
                return ["error" => true, "message" => "Kullanici durumu alinamadi."];
            }

            $gyms = UserGym::where(['uid' => $uid])->first();
            return [
                "success" => true,
                "completed" => UserGym::hasTrainingQualityToday($uid, 1, $gyms, $actionDay),
                "currentStrength" => (int) ($user->strength ?? 0),
                "strengthGain" => (int) (UserGym::$data['q1']['strength'] ?? 5),
                "trainingStreak" => UserGym::getDailyTrainingStreak($uid, $gyms, $actionDay),
                "resetAt" => date('c', strtotime('tomorrow')),
                "serverNow" => date('c'),
            ];
        } catch (\Exception $e) {
            return ["error" => true, "message" => "Egitim durumu alinamadi."];
        }
    }

    private function pickWheelReward(): array
    {
        $totalWeight = array_sum(array_column(self::WHEEL_REWARDS, 'weight'));
        $roll = random_int(1, $totalWeight);

        foreach (self::WHEEL_REWARDS as $reward) {
            $roll -= $reward['weight'];
            if ($roll <= 0) {
                return $reward;
            }
        }

        return self::WHEEL_REWARDS[0];
    }
}

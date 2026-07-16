<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

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

        if ($lastTime == self::serverDay()) {
            return true;
        }

        return false;
    }

    public function hasAnyTrainingToday()
    {
        foreach ([1, 2, 3, 4] as $quality) {
            $lastRaw = $this["q$quality"] ?? null;
            if (!empty($lastRaw) && date("Y-m-d", strtotime($lastRaw)) === self::serverDay()) {
                return true;
            }
        }

        return false;
    }

    public static function hasTrainingQualityToday($uid, $quality, $model = null, $actionDay = null)
    {
        $uid = (int) $uid;
        $quality = (int) $quality;
        $actionDay = $actionDay ?: self::serverDay();
        if ($uid <= 0 || $quality < 1 || $quality > 4) {
            return false;
        }

        if (!$model instanceof self) {
            $model = self::where(['uid' => $uid])->first();
        }

        if ($model instanceof self) {
            $lastRaw = $model["q$quality"] ?? null;
            if (!empty($lastRaw) && date('Y-m-d', strtotime($lastRaw)) === $actionDay) {
                return true;
            }
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('user_trainings')
            || !$schema->hasColumn('user_trainings', 'uid')
            || !$schema->hasColumn('user_trainings', 'quality')
            || !$schema->hasColumn('user_trainings', 'created_at')) {
            return false;
        }

        return DB::table('user_trainings')
            ->where('uid', $uid)
            ->where('quality', $quality)
            ->whereRaw('DATE(created_at) = ?', [$actionDay])
            ->exists();
    }

    public static function serverDay($timestamp = null): string
    {
        return date('Y-m-d', $timestamp === null ? time() : (int) $timestamp);
    }

    public static function getDailyTrainingStreak($uid, $model = null, $referenceDay = null): int
    {
        $uid = (int) $uid;
        $referenceDay = $referenceDay ?: self::serverDay();
        if ($uid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $referenceDay)) {
            return 0;
        }

        $trainingDays = [];
        if (!$model instanceof self) {
            $model = self::where(['uid' => $uid])->first();
        }

        if ($model instanceof self) {
            $day = self::normalizeTrainingDay($model->q1 ?? null);
            if ($day !== null && $day <= $referenceDay) {
                $trainingDays[$day] = true;
            }
        }

        $schema = DB::getSchemaBuilder();
        if ($schema->hasTable('user_gym_daily_actions')
            && $schema->hasColumn('user_gym_daily_actions', 'uid')
            && $schema->hasColumn('user_gym_daily_actions', 'action')
            && $schema->hasColumn('user_gym_daily_actions', 'action_day')) {
            $rows = DB::table('user_gym_daily_actions')
                ->where('uid', $uid)
                ->where('action', 'free_training')
                ->where('action_day', '<=', $referenceDay)
                ->orderBy('action_day', 'desc')
                ->get(['action_day']);

            foreach ($rows as $row) {
                $day = self::normalizeTrainingDay($row->action_day ?? null);
                if ($day !== null && $day <= $referenceDay) {
                    $trainingDays[$day] = true;
                }
            }
        }

        if ($schema->hasTable('user_trainings')
            && $schema->hasColumn('user_trainings', 'uid')
            && $schema->hasColumn('user_trainings', 'quality')
            && $schema->hasColumn('user_trainings', 'created_at')) {
            $rows = DB::table('user_trainings')
                ->where('uid', $uid)
                ->where('quality', 1)
                ->orderBy('created_at', 'desc')
                ->get(['created_at']);

            foreach ($rows as $row) {
                $day = self::normalizeTrainingDay($row->created_at ?? null);
                if ($day !== null && $day <= $referenceDay) {
                    $trainingDays[$day] = true;
                }
            }
        }

        if (empty($trainingDays)) {
            return 0;
        }

        $latestDay = max(array_keys($trainingDays));
        $today = new \DateTimeImmutable($referenceDay . ' 00:00:00');
        $yesterday = $today->modify('-1 day')->format('Y-m-d');
        if ($latestDay !== $referenceDay && $latestDay !== $yesterday) {
            return 0;
        }

        $streak = 0;
        $cursor = new \DateTimeImmutable($latestDay . ' 00:00:00');
        while (isset($trainingDays[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }

        return $streak;
    }

    private static function normalizeTrainingDay($value)
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : self::serverDay($timestamp);
    }

    public static function ensureDailyActionsTable(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();
            if ($schema->hasTable('user_gym_daily_actions')) {
                if (!$schema->hasColumn('user_gym_daily_actions', 'strength_after')) {
                    try {
                        $schema->table('user_gym_daily_actions', function (Blueprint $table) {
                            $table->integer('strength_after')->nullable();
                        });
                    } catch (\Exception $ignored) {
                        // Existing installations can continue without snapshots.
                    }
                }
                return true;
            }

            $schema->create('user_gym_daily_actions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('uid');
                $table->string('action', 32);
                $table->string('reward_key', 32)->nullable();
                $table->string('reward_type', 16)->nullable();
                $table->decimal('reward_amount', 11, 2)->nullable();
                $table->integer('strength_after')->nullable();
                $table->date('action_day');
                $table->dateTime('created_at');
                $table->unique(['uid', 'action', 'action_day'], 'uniq_user_gym_daily_action');
                $table->index(['uid', 'created_at'], 'idx_user_gym_daily_action_uid');
            });

            return true;
        } catch (\Exception $e) {
            try {
                return DB::getSchemaBuilder()->hasTable('user_gym_daily_actions');
            } catch (\Exception $ignored) {
                return false;
            }
        }
    }

    public static function hasDailyActionsColumn($column): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('user_gym_daily_actions')
                && DB::getSchemaBuilder()->hasColumn('user_gym_daily_actions', (string) $column);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function hasDailyActionToday($uid, $action, $actionDay = null): bool
    {
        $uid = (int) $uid;
        $actionDay = $actionDay ?: self::serverDay();
        if ($uid <= 0 || !self::ensureDailyActionsTable()) {
            return false;
        }

        return DB::table('user_gym_daily_actions')
            ->where('uid', $uid)
            ->where('action', (string) $action)
            ->where('action_day', $actionDay)
            ->exists();
    }

    public static function getDailyActionToday($uid, $action, $actionDay = null)
    {
        $uid = (int) $uid;
        $actionDay = $actionDay ?: self::serverDay();
        if ($uid <= 0 || !self::ensureDailyActionsTable()) {
            return null;
        }

        return DB::table('user_gym_daily_actions')
            ->where('uid', $uid)
            ->where('action', (string) $action)
            ->where('action_day', $actionDay)
            ->first();
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

        $today = self::serverDay();
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
                if (!empty($lastRaw) && date("Y-m-d", strtotime($lastRaw)) === self::serverDay()) {
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

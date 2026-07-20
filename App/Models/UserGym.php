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

    private static $schemaCapabilities;

    /** Cache schema metadata so one request does not repeat identical DDL checks. */
    private static function schemaCapabilities(): array
    {
        if (is_array(self::$schemaCapabilities)) {
            return self::$schemaCapabilities;
        }

        $capabilities = [
            'user_trainings' => ['table' => false, 'columns' => []],
            'daily_actions' => ['table' => false, 'columns' => []],
        ];

        try {
            $schema = DB::getSchemaBuilder();
            foreach ([
                'user_trainings' => ['uid', 'quality', 'created_at', 'strength_gained'],
                'daily_actions' => ['uid', 'action', 'reward_amount', 'action_day', 'created_at', 'reward_type', 'strength_after'],
            ] as $key => $columns) {
                $table = $key === 'daily_actions' ? 'user_gym_daily_actions' : 'user_trainings';
                if (!$schema->hasTable($table)) {
                    continue;
                }

                $capabilities[$key]['table'] = true;
                foreach ($columns as $column) {
                    $capabilities[$key]['columns'][$column] = $schema->hasColumn($table, $column);
                }
            }
        } catch (\Throwable $e) {
            // Keep the existing fail-closed behavior if metadata is unavailable.
        }

        return self::$schemaCapabilities = $capabilities;
    }

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

        $trainingSchema = self::schemaCapabilities()['user_trainings'];
        if (!$trainingSchema['table']
            || !$trainingSchema['columns']['uid']
            || !$trainingSchema['columns']['quality']
            || !$trainingSchema['columns']['created_at']) {
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

        $capabilities = self::schemaCapabilities();
        $dailySchema = $capabilities['daily_actions'];
        if ($dailySchema['table']
            && $dailySchema['columns']['uid']
            && $dailySchema['columns']['action']
            && $dailySchema['columns']['action_day']) {
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

        $trainingSchema = $capabilities['user_trainings'];
        if ($trainingSchema['table']
            && $trainingSchema['columns']['uid']
            && $trainingSchema['columns']['quality']
            && $trainingSchema['columns']['created_at']) {
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
        $schema = self::schemaCapabilities()['daily_actions'];
        foreach (['uid', 'action', 'reward_amount', 'action_day', 'created_at'] as $column) {
            if (!$schema['table'] || empty($schema['columns'][$column])) {
                return false;
            }
        }

        return true;
    }

    public static function hasDailyActionsColumn($column): bool
    {
        $schema = self::schemaCapabilities()['daily_actions'];
        return $schema['table'] && !empty($schema['columns'][(string) $column]);
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

        if (!self::schemaCapabilities()['user_trainings']['table']) {
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

        if (!self::schemaCapabilities()['user_trainings']['table']) {
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

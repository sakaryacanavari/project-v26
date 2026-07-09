<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;

class Shout extends Model
{
    protected $table = 'shouts';

    protected $fillable = [
        'uid',
        'parent_id',
        'body',
        'has_poll',
        'poll_question',
        'poll_data',
        'poll_total_votes',
        'poll_duration_hours',
        'poll_expires_at',
        'poll_cost_gold',
        'likes_count',
        'tips_gold_total',
        'reports_count',
        'is_state_decree',
        'decree_country_id',
        'decree_expires_at',
        'decree_cost_currency',
        'decree_cost_amount',
        'article_card_article_id',
        'is_deleted',
        'edited_at',
    ];

    public static function encodePollData(array $options)
    {
        $payload = [];
        foreach ($options as $option) {
            $optionKey = (int) ($option['option_key'] ?? 0);
            if ($optionKey < 1) {
                continue;
            }

            $payload[] = [
                'id' => $optionKey,
                'option_key' => $optionKey,
                'option_text' => (string) ($option['option_text'] ?? ''),
                'votes_count' => (int) ($option['votes_count'] ?? 0),
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public static function decodePollData($pollData)
    {
        if (empty($pollData)) {
            return [];
        }

        if (is_string($pollData)) {
            $pollData = json_decode($pollData, true);
        }

        if (!is_array($pollData)) {
            return [];
        }

        $options = [];
        foreach ($pollData as $option) {
            if (!is_array($option)) {
                $optionKey = (int) $option;
                continue;
            }

            $optionKey = (int) ($option['option_key'] ?? $option['id'] ?? 0);
            if ($optionKey < 1 && count($pollData) <= 3) {
                $arrayKeys = array_keys($pollData);
                $currentIndex = array_search($option, $pollData, true);
                if ($currentIndex !== false && isset($arrayKeys[$currentIndex])) {
                    $optionKey = (int) $arrayKeys[$currentIndex];
                }
            }
            if ($optionKey < 1) {
                continue;
            }

            $options[] = [
                'id' => $optionKey,
                'option_key' => $optionKey,
                'option_text' => (string) ($option['option_text'] ?? $option['text'] ?? ''),
                'votes_count' => (int) ($option['votes_count'] ?? $option['votes'] ?? 0),
            ];
        }

        if (empty($options)) {
            foreach ($pollData as $rawKey => $rawValue) {
                if (!is_scalar($rawValue)) {
                    continue;
                }

                $optionKey = (int) $rawKey;
                if ($optionKey < 1) {
                    continue;
                }

                $options[] = [
                    'id' => $optionKey,
                    'option_key' => $optionKey,
                    'option_text' => (string) $rawValue,
                    'votes_count' => 0,
                ];
            }
        }

        usort($options, function ($left, $right) {
            return (int) $left['option_key'] <=> (int) $right['option_key'];
        });

        return $options;
    }

    public static function incrementPollVote($shoutId, $optionKey, $updatedAt = null)
    {
        $shout = self::where('id', (int) $shoutId)->lockForUpdate()->first();
        if (!$shout || (int) ($shout->has_poll ?? 0) !== 1) {
            return false;
        }

        $options = self::decodePollData($shout->poll_data ?? null);
        $updated = false;
        foreach ($options as &$option) {
            if ((int) $option['option_key'] === (int) $optionKey) {
                $option['votes_count'] = (int) ($option['votes_count'] ?? 0) + 1;
                $updated = true;
                break;
            }
        }
        unset($option);

        if (!$updated) {
            return false;
        }

        return (bool) DB::table('shouts')
            ->where('id', (int) $shoutId)
            ->update([
                'poll_data' => self::encodePollData($options),
                'poll_total_votes' => DB::raw('poll_total_votes + 1'),
                'updated_at' => $updatedAt,
            ]);
    }

    public static function findPollVote($shoutId, $uid)
    {
        return DB::table('shout_poll_votes')
            ->where('shout_id', (int) $shoutId)
            ->where('uid', (int) $uid)
            ->lockForUpdate()
            ->first();
    }

    public static function createPollVote(array $attributes)
    {
        return DB::table('shout_poll_votes')->insertGetId([
            'shout_id' => (int) ($attributes['shout_id'] ?? 0),
            'poll_option_id' => (int) ($attributes['poll_option_id'] ?? 0),
            'uid' => (int) ($attributes['uid'] ?? 0),
            'created_at' => $attributes['created_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? null,
        ]);
    }

    public static function createTip(array $attributes)
    {
        return DB::table('shout_tips')->insertGetId([
            'shout_id' => (int) ($attributes['shout_id'] ?? 0),
            'from_uid' => (int) ($attributes['from_uid'] ?? 0),
            'to_uid' => (int) ($attributes['to_uid'] ?? 0),
            'gold_amount' => (float) ($attributes['gold_amount'] ?? 0),
            'created_at' => $attributes['created_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? null,
        ]);
    }

    public static function getPostingCounter($uid)
    {
        return DB::table('user_shout_limits')
            ->where('uid', (int) $uid)
            ->lockForUpdate()
            ->first();
    }

    public static function syncPostingCounter($uid, $now = null)
    {
        $uid = (int) $uid;
        $now = $now ?: date('Y-m-d H:i:s');
        $timestamp = strtotime($now);

        $counter = self::getPostingCounter($uid);
        if (!$counter) {
            DB::table('user_shout_limits')->insert([
                'uid' => $uid,
                'minute_window_started_at' => $now,
                'minute_count' => 0,
                'burst_window_started_at' => $now,
                'burst_count' => 0,
                'day_window_started_at' => date('Y-m-d 00:00:00', $timestamp),
                'daily_count' => 0,
                'last_shout_at' => null,
                'updated_at' => $now,
            ]);

            $counter = self::getPostingCounter($uid);
        }

        $minuteWindowStart = !empty($counter->minute_window_started_at) ? strtotime((string) $counter->minute_window_started_at) : 0;
        $burstWindowStart = !empty($counter->burst_window_started_at) ? strtotime((string) $counter->burst_window_started_at) : 0;
        $dayWindowStart = !empty($counter->day_window_started_at) ? strtotime((string) $counter->day_window_started_at) : 0;

        $minuteCount = ($minuteWindowStart && ($timestamp - $minuteWindowStart) < 60) ? (int) ($counter->minute_count ?? 0) : 0;
        $burstCount = ($burstWindowStart && ($timestamp - $burstWindowStart) < 300) ? (int) ($counter->burst_count ?? 0) : 0;
        $dailyCount = ($dayWindowStart && date('Y-m-d', $dayWindowStart) === date('Y-m-d', $timestamp)) ? (int) ($counter->daily_count ?? 0) : 0;

        return [
            'uid' => $uid,
            'minute_window_started_at' => $minuteCount > 0 ? date('Y-m-d H:i:s', $minuteWindowStart) : $now,
            'minute_count' => $minuteCount,
            'burst_window_started_at' => $burstCount > 0 ? date('Y-m-d H:i:s', $burstWindowStart) : $now,
            'burst_count' => $burstCount,
            'day_window_started_at' => $dailyCount > 0 ? date('Y-m-d H:i:s', $dayWindowStart) : date('Y-m-d 00:00:00', $timestamp),
            'daily_count' => $dailyCount,
            'last_shout_at' => !empty($counter->last_shout_at) ? (string) $counter->last_shout_at : null,
        ];
    }

    public static function updatePostingCounter($uid, array $state, $now = null)
    {
        $now = $now ?: date('Y-m-d H:i:s');

        return DB::table('user_shout_limits')
            ->where('uid', (int) $uid)
            ->update([
                'minute_window_started_at' => $state['minute_window_started_at'] ?? $now,
                'minute_count' => (int) ($state['minute_count'] ?? 0),
                'burst_window_started_at' => $state['burst_window_started_at'] ?? $now,
                'burst_count' => (int) ($state['burst_count'] ?? 0),
                'day_window_started_at' => $state['day_window_started_at'] ?? $now,
                'daily_count' => (int) ($state['daily_count'] ?? 0),
                'last_shout_at' => $state['last_shout_at'] ?? $now,
                'updated_at' => $now,
            ]);
    }
}

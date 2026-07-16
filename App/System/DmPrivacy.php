<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;

final class DmPrivacy
{
    public const ALLOW_EVERYONE = 'everyone';
    public const ALLOW_PARTY = 'party';
    public const ALLOW_NOBODY = 'nobody';

    public static function getDefaultPreferences(): array
    {
        return [
            'allow_from' => self::ALLOW_EVERYONE,
            'message_requests_enabled' => 0,
        ];
    }

    public static function getSupportState(): array
    {
        return [
            'friends' => false,
            'party' => self::hasPartySupport(),
            'message_requests' => false,
            'blocks' => false,
        ];
    }

    public static function getPreferences(int $uid): array
    {
        $defaults = self::getDefaultPreferences();
        if ($uid < 1 || !self::ensureTable()) {
            return $defaults;
        }

        return Cache::remember(Cache::userKey($uid, 'dm_privacy'), 30, function () use ($uid, $defaults) {
            try {
                $row = DB::table('dm_privacy_settings')->where('uid', $uid)->first();
                if (!$row) {
                    return $defaults;
                }

                return [
                    'allow_from' => self::normalizeAllowFrom((string) ($row->allow_from ?? $defaults['allow_from'])),
                    'message_requests_enabled' => (int) ($row->message_requests_enabled ?? $defaults['message_requests_enabled']),
                ];
            } catch (\Exception $e) {
                return $defaults;
            }
        });
    }

    public static function savePreferences(int $uid, array $preferences): bool
    {
        if ($uid < 1 || !self::ensureTable()) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'uid' => $uid,
            'allow_from' => self::normalizeAllowFrom((string) ($preferences['allow_from'] ?? self::ALLOW_EVERYONE)),
            'message_requests_enabled' => !empty($preferences['message_requests_enabled']) ? 1 : 0,
            'updated_at' => $now,
        ];

        try {
            $exists = DB::table('dm_privacy_settings')->where('uid', $uid)->exists();
            if ($exists) {
                $written = DB::table('dm_privacy_settings')->where('uid', $uid)->update($payload) !== false;
            } else {
                $payload['created_at'] = $now;
                $written = (bool) DB::table('dm_privacy_settings')->insert($payload);
            }

            if ($written) {
                Cache::forget(Cache::userKey($uid, 'dm_privacy'));
            }

            return $written;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function canStartDm(int $fromUid, int $toUid): array
    {
        if ($fromUid < 1 || $toUid < 1 || $fromUid === $toUid) {
            return ['allowed' => false, 'message' => 'Gecersiz DM hedefi.'];
        }

        $preferences = self::getPreferences($toUid);
        $allowFrom = $preferences['allow_from'];

        if ($allowFrom === self::ALLOW_NOBODY) {
            return ['allowed' => false, 'message' => 'Bu oyuncu yeni DM kabul etmiyor.'];
        }

        if ($allowFrom === self::ALLOW_PARTY && !self::isPartyOrCoalitionMember($fromUid, $toUid)) {
            return ['allowed' => false, 'message' => 'Bu oyuncu sadece parti veya ittifak uyelerinden DM kabul ediyor.'];
        }

        return ['allowed' => true, 'message' => ''];
    }

    private static function ensureTable(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();
            if (!$schema->hasTable('dm_privacy_settings')) {
                return false;
            }

            foreach (['uid', 'allow_from', 'message_requests_enabled'] as $column) {
                if (!$schema->hasColumn('dm_privacy_settings', $column)) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function normalizeAllowFrom(string $value): string
    {
        $value = trim($value);
        $allowed = [self::ALLOW_EVERYONE, self::ALLOW_PARTY, self::ALLOW_NOBODY];

        return in_array($value, $allowed, true) ? $value : self::ALLOW_EVERYONE;
    }

    private static function hasPartySupport(): bool
    {
        try {
            $schema = DB::getSchemaBuilder();
            return $schema->hasTable('party_members') && $schema->hasTable('political_parties');
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function isPartyOrCoalitionMember(int $fromUid, int $toUid): bool
    {
        if (!self::hasPartySupport()) {
            return false;
        }

        try {
            $fromMember = DB::table('party_members')->where('uid', $fromUid)->first();
            $toMember = DB::table('party_members')->where('uid', $toUid)->first();

            if (!$fromMember || !$toMember || empty($fromMember->party) || empty($toMember->party)) {
                return false;
            }

            if ((int) $fromMember->party === (int) $toMember->party) {
                return true;
            }

            $schema = DB::getSchemaBuilder();
            if (!$schema->hasColumn('political_parties', 'coalition_id')) {
                return false;
            }

            $parties = DB::table('political_parties')
                ->whereIn('id', [(int) $fromMember->party, (int) $toMember->party])
                ->get()
                ->keyBy('id');

            $fromParty = $parties[(int) $fromMember->party] ?? null;
            $toParty = $parties[(int) $toMember->party] ?? null;

            return $fromParty && $toParty
                && !empty($fromParty->coalition_id)
                && (int) $fromParty->coalition_id === (int) $toParty->coalition_id;
        } catch (\Exception $e) {
            return false;
        }
    }
}

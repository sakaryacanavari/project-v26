<?php

namespace App\System;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;

/**
 * Idempotent application schema maintenance.
 *
 * This class is called by CLI commands only. HTTP code may inspect schema
 * readiness but must never call DDL.
 */
final class SchemaMigrations
{
    public static function run(array $scopes = []): array
    {
        $runAll = empty($scopes);
        $completed = [];

        if ($runAll || in_array('player', $scopes, true)) {
            self::migrateTraining();
            self::migrateNotifications();
            self::migrateDmPrivacy();
            self::migrateGameExperience();
            $completed[] = 'player';
        }

        if ($runAll || in_array('market', $scopes, true)) {
            self::migrateMarket();
            $completed[] = 'market';
        }

        return ['completed' => $completed];
    }

    private static function migrateTraining(): void
    {
        $schema = DB::getSchemaBuilder();

        if (!$schema->hasTable('user_trainings')) {
            DB::statement(
                'CREATE TABLE user_trainings (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    uid BIGINT UNSIGNED NOT NULL,
                    quality TINYINT UNSIGNED NOT NULL,
                    strength_gained INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    training_day DATE GENERATED ALWAYS AS (CAST(created_at AS DATE)) STORED,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_training_day (uid, quality, training_day),
                    KEY idx_user_trainings_uid (uid),
                    KEY idx_user_trainings_quality (quality),
                    KEY idx_user_trainings_created_at (created_at),
                    KEY idx_user_trainings_uid_quality (uid, quality)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            self::requireColumns('user_trainings', ['uid', 'quality', 'strength_gained', 'created_at']);

            if (!$schema->hasColumn('user_trainings', 'training_day')) {
                DB::statement(
                    'ALTER TABLE user_trainings
                     ADD COLUMN training_day DATE GENERATED ALWAYS AS (CAST(created_at AS DATE)) STORED'
                );
            }

            if (!self::indexExists('user_trainings', 'uniq_training_day')) {
                DB::statement(
                    'ALTER TABLE user_trainings
                     ADD UNIQUE INDEX uniq_training_day (uid, quality, training_day)'
                );
            }
        }

        if (!$schema->hasTable('user_gym_daily_actions')) {
            $schema->create('user_gym_daily_actions', function (Blueprint $table): void {
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
            return;
        }

        self::requireColumns('user_gym_daily_actions', ['uid', 'action', 'action_day', 'created_at']);
        self::addColumnIfMissing('user_gym_daily_actions', 'reward_key', static function (Blueprint $table): void {
            $table->string('reward_key', 32)->nullable();
        });
        self::addColumnIfMissing('user_gym_daily_actions', 'reward_type', static function (Blueprint $table): void {
            $table->string('reward_type', 16)->nullable();
        });
        self::addColumnIfMissing('user_gym_daily_actions', 'reward_amount', static function (Blueprint $table): void {
            $table->decimal('reward_amount', 11, 2)->nullable();
        });
        self::addColumnIfMissing('user_gym_daily_actions', 'strength_after', static function (Blueprint $table): void {
            $table->integer('strength_after')->nullable();
        });
    }

    private static function migrateNotifications(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('notification_preferences')) {
            $schema->create('notification_preferences', function (Blueprint $table): void {
                $table->unsignedInteger('uid')->primary();
                $table->tinyInteger('dm_enabled')->default(1);
                $table->tinyInteger('news_enabled')->default(1);
                $table->tinyInteger('system_enabled')->default(1);
                $table->tinyInteger('quiet_hours_enabled')->default(0);
                $table->string('quiet_start', 5)->default('22:00');
                $table->string('quiet_end', 5)->default('08:00');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
            return;
        }

        self::requireColumns('notification_preferences', ['uid']);
        self::addColumnIfMissing('notification_preferences', 'dm_enabled', static function (Blueprint $table): void {
            $table->tinyInteger('dm_enabled')->default(1);
        });
        self::addColumnIfMissing('notification_preferences', 'news_enabled', static function (Blueprint $table): void {
            $table->tinyInteger('news_enabled')->default(1);
        });
        self::addColumnIfMissing('notification_preferences', 'system_enabled', static function (Blueprint $table): void {
            $table->tinyInteger('system_enabled')->default(1);
        });
        self::addColumnIfMissing('notification_preferences', 'quiet_hours_enabled', static function (Blueprint $table): void {
            $table->tinyInteger('quiet_hours_enabled')->default(0);
        });
        self::addColumnIfMissing('notification_preferences', 'quiet_start', static function (Blueprint $table): void {
            $table->string('quiet_start', 5)->default('22:00');
        });
        self::addColumnIfMissing('notification_preferences', 'quiet_end', static function (Blueprint $table): void {
            $table->string('quiet_end', 5)->default('08:00');
        });
        self::addColumnIfMissing('notification_preferences', 'created_at', static function (Blueprint $table): void {
            $table->dateTime('created_at')->nullable();
        });
        self::addColumnIfMissing('notification_preferences', 'updated_at', static function (Blueprint $table): void {
            $table->dateTime('updated_at')->nullable();
        });
    }

    private static function migrateDmPrivacy(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('dm_privacy_settings')) {
            $schema->create('dm_privacy_settings', function (Blueprint $table): void {
                $table->unsignedInteger('uid')->primary();
                $table->string('allow_from', 20)->default(DmPrivacy::ALLOW_EVERYONE);
                $table->tinyInteger('message_requests_enabled')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
            return;
        }

        self::requireColumns('dm_privacy_settings', ['uid']);
        self::addColumnIfMissing('dm_privacy_settings', 'allow_from', static function (Blueprint $table): void {
            $table->string('allow_from', 20)->default(DmPrivacy::ALLOW_EVERYONE);
        });
        self::addColumnIfMissing('dm_privacy_settings', 'message_requests_enabled', static function (Blueprint $table): void {
            $table->tinyInteger('message_requests_enabled')->default(0);
        });
        self::addColumnIfMissing('dm_privacy_settings', 'created_at', static function (Blueprint $table): void {
            $table->dateTime('created_at')->nullable();
        });
        self::addColumnIfMissing('dm_privacy_settings', 'updated_at', static function (Blueprint $table): void {
            $table->dateTime('updated_at')->nullable();
        });
    }

    private static function migrateGameExperience(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('game_experience_settings')) {
            $schema->create('game_experience_settings', function (Blueprint $table): void {
                $table->unsignedInteger('uid')->primary();
                $table->string('ui_density', 20)->default('balanced');
                $table->string('animation_level', 20)->default('balanced');
                $table->string('left_hud', 20)->default('detailed');
                $table->string('shout_width', 20)->default('balanced');
                $table->string('home_layout', 30)->default('wide');
                $table->string('home_priority', 20)->default('news');
                $table->text('quick_favorites')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
            return;
        }

        self::requireColumns('game_experience_settings', ['uid']);
        self::addColumnIfMissing('game_experience_settings', 'ui_density', static function (Blueprint $table): void {
            $table->string('ui_density', 20)->default('balanced');
        });
        self::addColumnIfMissing('game_experience_settings', 'animation_level', static function (Blueprint $table): void {
            $table->string('animation_level', 20)->default('balanced');
        });
        self::addColumnIfMissing('game_experience_settings', 'left_hud', static function (Blueprint $table): void {
            $table->string('left_hud', 20)->default('detailed');
        });
        self::addColumnIfMissing('game_experience_settings', 'shout_width', static function (Blueprint $table): void {
            $table->string('shout_width', 20)->default('balanced');
        });
        self::addColumnIfMissing('game_experience_settings', 'home_layout', static function (Blueprint $table): void {
            $table->string('home_layout', 30)->default('wide');
        });
        self::addColumnIfMissing('game_experience_settings', 'home_priority', static function (Blueprint $table): void {
            $table->string('home_priority', 20)->default('news');
        });
        self::addColumnIfMissing('game_experience_settings', 'quick_favorites', static function (Blueprint $table): void {
            $table->text('quick_favorites')->nullable();
        });
        self::addColumnIfMissing('game_experience_settings', 'created_at', static function (Blueprint $table): void {
            $table->dateTime('created_at')->nullable();
        });
        self::addColumnIfMissing('game_experience_settings', 'updated_at', static function (Blueprint $table): void {
            $table->dateTime('updated_at')->nullable();
        });
    }

    private static function migrateMarket(): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('item_offers')) {
            throw new \RuntimeException('Base market table item_offers is missing.');
        }

        $statusAdded = !$schema->hasColumn('item_offers', 'status');
        $listedQuantityAdded = !$schema->hasColumn('item_offers', 'listed_quantity');

        self::addColumnIfMissing('item_offers', 'status', static function (Blueprint $table): void {
            $table->string('status', 16)->default(MarketOrderService::STATUS_OPEN);
        });
        self::addColumnIfMissing('item_offers', 'listed_quantity', static function (Blueprint $table): void {
            $table->integer('listed_quantity')->default(0);
        });
        self::addColumnIfMissing('item_offers', 'expires_at', static function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable();
        });
        self::addColumnIfMissing('item_offers', 'closed_at', static function (Blueprint $table): void {
            $table->dateTime('closed_at')->nullable();
        });

        if ($statusAdded) {
            DB::statement("UPDATE item_offers SET status = 'open' WHERE status IS NULL OR status = ''");
        }
        if ($listedQuantityAdded) {
            DB::statement('UPDATE item_offers SET listed_quantity = quantity WHERE listed_quantity = 0');
        }

        if (!$schema->hasTable('market_order_events')) {
            $schema->create('market_order_events', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('offer_id')->nullable();
                $table->integer('uid');
                $table->integer('item');
                $table->integer('quality')->default(0);
                $table->integer('country')->default(0);
                $table->string('event_type', 16);
                $table->string('status', 16)->nullable();
                $table->integer('quantity')->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('currency', 20)->nullable();
                $table->dateTime('created_at');
                $table->index(['uid', 'created_at'], 'market_order_events_uid_created');
                $table->index(['offer_id', 'created_at'], 'market_order_events_offer_created');
            });
        }

        if (!$schema->hasTable('user_storage_settings')) {
            $schema->create('user_storage_settings', function (Blueprint $table): void {
                $table->integer('uid')->primary();
                $table->integer('capacity')->default(MarketOrderService::DEFAULT_STORAGE_CAPACITY);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        $indexes = [
            'idx_item_offers_market_active' => '(status, country, item, quality, price)',
            'idx_item_offers_expiry' => '(status, expires_at, uid)',
            'idx_item_offers_user_open_expiry' => '(uid, status, expires_at, created_at)',
        ];
        foreach ($indexes as $name => $columns) {
            if (!self::indexExists('item_offers', $name)) {
                DB::statement('ALTER TABLE item_offers ADD INDEX ' . $name . ' ' . $columns);
            }
        }
    }

    private static function addColumnIfMissing(string $table, string $column, callable $definition): void
    {
        $schema = DB::getSchemaBuilder();
        if (!$schema->hasColumn($table, $column)) {
            $schema->table($table, $definition);
        }
    }

    private static function requireColumns(string $table, array $columns): void
    {
        $schema = DB::getSchemaBuilder();
        foreach ($columns as $column) {
            if (!$schema->hasColumn($table, $column)) {
                throw new \RuntimeException('Required schema column is missing: ' . $table . '.' . $column);
            }
        }
    }

    private static function indexExists(string $table, string $index): bool
    {
        foreach (DB::select('SHOW INDEX FROM ' . $table) as $row) {
            if ((string) ($row->Key_name ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
}

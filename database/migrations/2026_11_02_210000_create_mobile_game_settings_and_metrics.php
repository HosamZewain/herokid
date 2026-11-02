<?php

use App\Services\MobileGames\GameRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_game_configs', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('version')->default(1);
            $t->json('data');
            $t->timestamp('updated_at');
        });
        Schema::create('mobile_game_config_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('version')->unique();
            $t->json('data');
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('restored_from')->nullable();
            $t->timestamp('created_at');
        });
        $data = json_encode(GameRegistry::defaults(), JSON_THROW_ON_ERROR);
        DB::table('mobile_game_configs')->insert(['id' => 1, 'version' => 1, 'data' => $data, 'updated_at' => now()]);
        DB::table('mobile_game_config_versions')->insert(['version' => 1, 'data' => $data, 'created_at' => now()]);
        Schema::create('mobile_game_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('fingerprint', 64);
            $t->string('name', 24);
            $t->string('platform', 7);
            $t->string('app_version', 32);
            $t->string('game_id', 24)->nullable();
            $t->unsignedSmallInteger('level')->nullable();
            $t->unsignedInteger('rules_version')->nullable();
            $t->uuid('round_id')->nullable()->index();
            $t->uuid('session_id')->nullable();
            $t->string('screen', 24)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedInteger('moves')->nullable();
            $t->dateTime('occurred_at', 3)->index();
            $t->dateTime('received_at', 3)->index();
        });
        Schema::create('mobile_game_rounds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('game_id', 24);
            $t->unsignedSmallInteger('level');
            $t->unsignedInteger('rules_version');
            $t->string('platform', 7);
            $t->string('app_version', 32);
            $t->dateTime('started_at', 3)->nullable()->index();
            $t->dateTime('completed_at', 3)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedInteger('moves')->nullable();
            $t->boolean('paired')->default(false);
            $t->dateTime('received_at', 3)->index();
        });
        Schema::create('mobile_game_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('platform', 7);
            $t->string('app_version', 32);
            $t->dateTime('started_at', 3)->index();
            $t->dateTime('latest_at', 3);
            $t->dateTime('received_at', 3)->index();
        });
        Schema::create('mobile_game_daily', function (Blueprint $t) {
            $t->id();
            $t->date('day'); // Cairo calendar date, derived with timezone rules.
            $t->string('platform', 7);
            $t->string('app_version', 32);
            $t->string('game_id', 24)->default('-');
            $t->unsignedSmallInteger('level')->default(0);
            $t->unsignedInteger('rules_version')->default(0);
            foreach (['app_opened', 'screen_viewed', 'game_opened', 'game_started', 'game_completed', 'matched_completed', 'duration_sum', 'duration_count', 'moves_sum', 'moves_count', 'sessions_started'] as $field) {
                $t->unsignedBigInteger($field)->default(0);
            }
            $t->dateTime('last_received_at', 3);
            $t->unique(['day', 'platform', 'app_version', 'game_id', 'level', 'rules_version'], 'mobile_game_daily_dimensions');
            $t->index(['game_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['mobile_game_daily', 'mobile_game_sessions', 'mobile_game_rounds', 'mobile_game_events', 'mobile_game_config_versions', 'mobile_game_configs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

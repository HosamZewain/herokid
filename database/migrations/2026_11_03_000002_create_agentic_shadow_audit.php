<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_checkout_states', function (Blueprint $t) {
            $t->id();
            $t->string('checkout_key')->unique();
            $t->unsignedBigInteger('representative_id')->nullable();
            $t->unsignedInteger('context_version')->default(0);
            $t->string('fingerprint', 64)->nullable();
            $t->longText('snapshot')->nullable();
            $t->json('covered_targets')->nullable();
            $t->unsignedInteger('target_count')->default(0);
            $t->boolean('blocked')->default(false);
            $t->string('status', 32)->default('unobserved')->index();
            $t->string('error_code', 80)->nullable();
            $t->timestamp('dirty_at')->nullable()->index();
            $t->timestamp('next_evaluation_at')->nullable()->index();
            $t->timestamp('observed_at')->nullable();
            $t->timestamp('evaluated_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ai_agent_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('checkout_state_id')->constrained('ai_checkout_states');
            $t->unsignedInteger('context_version');
            $t->string('fingerprint', 64);
            $t->longText('snapshot');
            $t->string('provider', 40);
            $t->json('provider_metadata')->nullable();
            $t->string('policy_version', 80);
            $t->string('prompt_version', 80);
            $t->string('status', 32);
            $t->string('error_code', 80)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['checkout_state_id', 'context_version']);
        });
        Schema::create('ai_order_decisions', function (Blueprint $t) {
            $t->id();
            $t->uuid('agent_run_id')->unique();
            $t->foreign('agent_run_id')->references('id')->on('ai_agent_runs');
            $t->json('decision');
            $t->json('policy');
            $t->json('simulation');
            $t->timestamps();
        });
        // A single row provides portable atomic quota/lease/fairness/cursor control.
        Schema::create('ai_runtime_states', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->string('quota_day', 10)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedBigInteger('turns')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('provider_error', 80)->nullable();
            $t->unsignedBigInteger('scan_cursor')->default(0);
            $t->timestamp('reconciled_at')->nullable();
            $t->timestamps();
        });
        DB::table('ai_runtime_states')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Forward-only: keep audit and quota evidence when stopping observation.
    }
};

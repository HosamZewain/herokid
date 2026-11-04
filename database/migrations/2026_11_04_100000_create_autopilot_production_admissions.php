<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_production_controls', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('paused')->default(false);
            $t->unsignedBigInteger('paused_by')->nullable();
            $t->timestamp('paused_at')->nullable();
            $t->string('quota_day', 10)->nullable();
            $t->unsignedInteger('admissions')->default(0);
            $t->timestamps();
        });
        DB::table('ai_production_controls')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('ai_production_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('decision_id')->unique()->constrained('ai_order_decisions');
            $t->string('idempotency_key', 64)->unique();
            $t->string('activation_id', 80);
            $t->string('checkout_key')->index();
            $t->unsignedBigInteger('order_id')->index();
            $t->string('target_id');
            $t->string('action', 80);
            $t->string('context_fingerprint', 64);
            $t->string('status', 32);
            $t->string('reason_code', 80)->nullable();
            $t->string('executor_type', 32)->nullable();
            $t->string('executor_id', 80)->nullable();
            $t->index(['executor_type', 'executor_id', 'status'], 'ai_execution_native_lookup');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Keep admission and budget receipts. Disable dispatch to roll back safely.
    }
};

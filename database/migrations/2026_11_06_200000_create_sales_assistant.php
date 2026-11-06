<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('session_hash', 64)->index();
            $t->char('visitor_hash', 64)->index();
            $t->boolean('upsell_declined')->default(false);
            $t->timestamps();
        });
        Schema::create('sales_turns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained('sales_conversations')->cascadeOnDelete();
            $t->uuid('client_nonce');
            $t->string('status', 24)->index();
            $t->text('request_text');
            $t->longText('result')->nullable();
            $t->json('allowed_items')->nullable();
            $t->date('budget_day')->index();
            $t->unsignedInteger('reserved_micros')->default(0);
            $t->unsignedInteger('spent_micros')->default(0);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('cached_tokens')->default(0);
            $t->unsignedInteger('reasoning_tokens')->default(0);
            $t->unsignedTinyInteger('tool_calls')->default(0);
            $t->char('capability_nonce', 64);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'client_nonce']);
        });
        Schema::create('sales_daily_budgets', function (Blueprint $t) {
            $t->date('day')->primary();
            $t->unsignedBigInteger('reserved_micros')->default(0);
            $t->unsignedBigInteger('spent_micros')->default(0);
        });
        Schema::create('sales_session_days', function (Blueprint $t) {
            $t->id();
            $t->char('session_hash', 64);
            $t->date('day');
            $t->unsignedInteger('turns')->default(0);
            $t->unique(['session_hash', 'day']);
        });
        Schema::create('sales_catalog_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('catalog_key', 40)->unique();
            $t->text('keywords');
            $t->timestamps();
        });
        Schema::create('sales_assistant_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('conversation_id')->index();
            $t->char('visitor_hash', 64)->index();
            $t->uuid('turn_id')->nullable()->index();
            $t->json('metrics')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('cached_tokens')->default(0);
            $t->unsignedInteger('reasoning_tokens')->default(0);
            $t->unsignedInteger('spent_micros')->default(0);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->unsignedTinyInteger('tool_calls')->default(0);
            $t->string('type', 32);
            $t->string('catalog_key', 40)->nullable();
            $t->string('checkout_key', 100)->nullable()->unique();
            $t->unsignedBigInteger('order_value_cents')->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['sales_assistant_events', 'sales_catalog_profiles', 'sales_session_days', 'sales_daily_budgets', 'sales_turns', 'sales_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

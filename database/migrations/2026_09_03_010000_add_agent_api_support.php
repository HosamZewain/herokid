<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('agent_api_enabled')->default(false)->after('is_active')->index();
        });

        Schema::table('order_attachments', function (Blueprint $table): void {
            $table->string('production_unit_key')->nullable()->after('order_id')->index();
        });

        Schema::create('agent_api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 120);
            $table->char('key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->string('checkout_group_key')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'key_hash'], 'agent_api_idempotency_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_api_idempotency_keys');

        Schema::table('order_attachments', function (Blueprint $table): void {
            $table->dropColumn('production_unit_key');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('agent_api_enabled');
        });
    }
};

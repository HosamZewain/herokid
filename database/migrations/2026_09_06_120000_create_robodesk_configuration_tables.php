<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robodesk_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('credential_type', 50)->unique();
            $table->text('encrypted_value');
            $table->string('last_four', 8)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // One row per action. `params` holds every RoboDesk-specific value the
        // action needs (endpoint, template, payload shape, channel...), so the
        // contract can be plugged in from the admin panel without a deployment.
        Schema::create('robodesk_action_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key')->unique();
            $table->boolean('is_enabled')->default(false)->index();
            $table->json('params')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('order_csat_responses', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('source', 30)->default('robodesk');
            $table->string('external_message_id')->nullable()->unique();
            $table->string('external_conversation_id')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_csat_responses');
        Schema::dropIfExists('robodesk_action_settings');
        Schema::dropIfExists('robodesk_credentials');
    }
};

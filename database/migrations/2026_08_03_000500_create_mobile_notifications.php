<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 80)->index();
            $table->string('category', 20)->default('operational');
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('mobile_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_installation_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('provider_ticket_id')->nullable()->index();
            $table->string('error_code')->nullable();
            $table->text('safe_error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['mobile_notification_id', 'device_installation_id'], 'mobile_push_notification_device_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_deliveries');
        Schema::dropIfExists('mobile_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable()->after('last_seen_at');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_requested_at');
        });

        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 50)->index();
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_uuid')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->longText('reason')->nullable();
            $table->json('scope')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'requested_at']);
        });

        Schema::create('mobile_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('device_installation_uuid')->nullable()->index();
            $table->string('event_name', 80)->index();
            $table->json('properties')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('platform', 20)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['event_name', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_analytics_events');
        Schema::dropIfExists('privacy_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('birth_date')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('gender', 20)->nullable();
            $table->json('interests')->nullable();
            $table->string('preferred_language', 5)->default('ar');
            $table->string('profile_photo_disk', 50)->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->timestamp('photo_reuse_consent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('recipient_name');
            $table->string('phone', 50);
            $table->foreignId('delivery_country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city');
            $table->string('street');
            $table->text('details');
            $table->text('delivery_instructions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'is_default']);
        });

        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->timestamps();
            $table->unique(['user_id', 'item_type', 'item_id']);
        });

        Schema::create('mobile_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('draft_type', 40);
            $table->string('status', 30)->default('active');
            $table->longText('payload');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status']);
        });

        Schema::create('device_installations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('app_version', 40)->nullable();
            $table->string('device_name')->nullable();
            $table->string('locale', 10)->default('ar');
            $table->string('timezone', 80)->nullable();
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable()->index();
            $table->boolean('marketing_notifications')->default(false);
            $table->boolean('operational_notifications')->default(true);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consent_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('consent_type', 80);
            $table->string('document_version', 40);
            $table->boolean('granted');
            $table->timestamp('recorded_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('source', 30)->default('mobile');
            $table->string('ip_hash', 64)->nullable();
            $table->string('device_installation_uuid')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consent_type', 'recorded_at'], 'consents_user_type_recorded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('device_installations');
        Schema::dropIfExists('mobile_drafts');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('child_profiles');
    }
};

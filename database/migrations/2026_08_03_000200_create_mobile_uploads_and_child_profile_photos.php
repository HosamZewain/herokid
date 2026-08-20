<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 50);
            $table->string('original_filename');
            $table->string('declared_mime_type', 100);
            $table->unsignedBigInteger('expected_size');
            $table->unsignedInteger('chunk_size');
            $table->json('chunks')->nullable();
            $table->unsignedBigInteger('received_size')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->string('disk', 50)->default('local');
            $table->string('path')->nullable();
            $table->string('verified_mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'status']);
        });

        Schema::create('child_profile_photos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('child_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mobile_upload_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('reuse_consent_at');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['child_profile_id', 'status']);
        });

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->foreignId('child_profile_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->uuid('mobile_idempotency_key')->nullable()->unique()->after('child_profile_id');
            $table->string('identity_type', 40)->default('original')->after('gender');
            $table->string('identity_theme', 80)->nullable()->after('identity_type');
        });
    }

    public function down(): void
    {
        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('child_profile_id');
            $table->dropColumn(['mobile_idempotency_key', 'identity_type', 'identity_theme']);
        });
        Schema::dropIfExists('child_profile_photos');
        Schema::dropIfExists('mobile_uploads');
    }
};

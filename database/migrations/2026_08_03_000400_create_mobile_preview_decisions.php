<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('approved_booklet_preview_version_id')->nullable()->after('child_identity_approved_attempt_id')->constrained('booklet_preview_versions')->nullOnDelete();
            $table->timestamp('preview_approved_at')->nullable()->after('approved_booklet_preview_version_id');
        });

        Schema::create('booklet_preview_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booklet_preview_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booklet_preview_version_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 30);
            $table->unsignedInteger('page_number')->nullable();
            $table->longText('comments')->nullable();
            $table->string('device_installation_uuid')->nullable();
            $table->string('device_fingerprint_hash', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['order_id', 'decision', 'decided_at'], 'preview_decisions_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booklet_preview_decisions');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_booklet_preview_version_id');
            $table->dropColumn('preview_approved_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 20);
            $table->string('category', 20);
            $table->string('title', 120);
            $table->text('body');
            $table->json('data');
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('preview_recipients')->default(0);
            $table->timestamps();
        });
        Schema::table('mobile_notifications', function (Blueprint $table): void {
            $table->foreignId('mobile_push_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['mobile_push_campaign_id', 'user_id'], 'mobile_campaign_user_unique');
        });
        Schema::table('mobile_push_deliveries', function (Blueprint $table): void {
            $table->string('receipt_status', 30)->nullable()->index();
            $table->timestamp('receipt_checked_at')->nullable();
            $table->string('sent_token_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_push_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['receipt_status']);
            $table->dropColumn(['receipt_status', 'receipt_checked_at', 'sent_token_hash']);
        });
        Schema::table('mobile_notifications', function (Blueprint $table): void {
            $table->dropUnique('mobile_campaign_user_unique');
            $table->dropConstrainedForeignId('mobile_push_campaign_id');
        });
        Schema::dropIfExists('mobile_push_campaigns');
    }
};

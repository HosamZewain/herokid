<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('child_identity_generation_attempts', function (Blueprint $table): void {
            $table->string('share_draft_token', 64)->nullable()->unique()->after('preview_storage_path');
            $table->string('share_feed_card_path')->nullable()->after('share_draft_token');
            $table->string('share_story_card_path')->nullable()->after('share_feed_card_path');
            $table->string('share_og_card_path')->nullable()->after('share_story_card_path');
            $table->string('share_card_fingerprint', 64)->nullable()->after('share_og_card_path');
            $table->timestamp('share_cards_generated_at')->nullable()->after('share_card_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('child_identity_generation_attempts', function (Blueprint $table): void {
            $table->dropUnique(['share_draft_token']);
            $table->dropColumn([
                'share_draft_token',
                'share_feed_card_path',
                'share_story_card_path',
                'share_og_card_path',
                'share_card_fingerprint',
                'share_cards_generated_at',
            ]);
        });
    }
};

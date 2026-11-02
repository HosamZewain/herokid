<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_review_manifests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->unique()->constrained('order_process_runs');
            $t->string('checkout_key')->index();
            $t->longText('images');
            $t->string('fingerprint', 64);
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('response_message_id')->nullable()->unique();
            $t->timestamps();
        });
        Schema::table('codex_scene_runs', function (Blueprint $t) {
            $t->string('production_kind', 32)->default('story');
            $t->unsignedBigInteger('order_item_id')->nullable()->index();
            $t->unsignedInteger('scene_count')->default(13);
        });
    }

    public function down(): void
    { /* Preserve approvals, page attempts and media evidence. */
    }
};

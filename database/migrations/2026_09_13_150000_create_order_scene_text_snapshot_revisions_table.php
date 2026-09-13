<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_scene_text_snapshot_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('order_scene_text_snapshots')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('previous_snapshot');
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_scene_text_snapshot_revisions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codex_scene_runs', function (Blueprint $table) {
            $table->dropUnique(['active_slot']);
            $table->unique(['order_id', 'active_slot']);
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->string('source', 16)->default('manual');
            $table->unsignedTinyInteger('protocol')->default(1);
        });
    }

    public function down(): void
    { /* Preserve queued work and audit history. */ }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codex_scene_runs', function (Blueprint $table) {
            $table->json('timing')->nullable();
        });
    }

    public function down(): void
    { /* Preserve production timing evidence. */
    }
};

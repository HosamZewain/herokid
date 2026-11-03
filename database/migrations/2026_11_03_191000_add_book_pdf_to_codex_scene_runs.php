<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codex_scene_runs', function (Blueprint $table) {
            $table->json('book_pdf')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve published file references and audit history on code rollback.
    }
};

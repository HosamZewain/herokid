<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_production_specs', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 100);
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->timestamps();
            $table->unique(['scope_key', 'version']);
        });
    }

    public function down(): void
    {
        // Retain specifications referenced by immutable decision audit records.
    }
};

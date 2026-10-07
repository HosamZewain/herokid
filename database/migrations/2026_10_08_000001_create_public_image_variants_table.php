<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_image_variants', function (Blueprint $table): void {
            $table->id();
            $table->char('source_key', 64)->unique();
            $table->string('source_disk');
            $table->text('source_path');
            $table->char('source_hash', 64);
            $table->json('variants');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_image_variants');
    }
};

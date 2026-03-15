<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_story_category', function (Blueprint $table) {
            $table->foreignId('story_id')->constrained()->onDelete('cascade');
            $table->foreignId('story_category_id')->constrained()->onDelete('cascade');
            $table->primary(['story_id', 'story_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_story_category');
    }
};

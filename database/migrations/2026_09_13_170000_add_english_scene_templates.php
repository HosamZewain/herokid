<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_scene_templates', function (Blueprint $table) {
            $table->text('english_male_text_template')->nullable();
            $table->text('english_female_text_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('story_scene_templates', fn (Blueprint $table) => $table->dropColumn(['english_male_text_template', 'english_female_text_template']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_projects', function (Blueprint $table) {
            $table->string('template_hero_name')->nullable()->after('current_stage');
            $table->string('template_hero_gender', 20)->nullable()->after('template_hero_name');
            $table->string('personalized_hero_name')->nullable()->after('template_hero_gender');
            $table->string('child_story_role')->nullable()->after('personalized_hero_name');
            $table->string('personalization_status')->default('pending')->after('child_story_role')->index();
            $table->json('personalization_warnings')->nullable()->after('personalization_status');
        });

        Schema::table('production_scenes', function (Blueprint $table) {
            $table->json('original_template_data_json')->nullable()->after('review_notes');
            $table->string('template_hero_name')->nullable()->after('original_template_data_json');
            $table->string('personalized_hero_name')->nullable()->after('template_hero_name');
            $table->string('personalization_status')->default('pending')->after('personalized_hero_name')->index();
            $table->json('personalization_warnings')->nullable()->after('personalization_status');
        });
    }

    public function down(): void
    {
        Schema::table('production_scenes', function (Blueprint $table) {
            $table->dropIndex(['personalization_status']);
            $table->dropColumn([
                'original_template_data_json',
                'template_hero_name',
                'personalized_hero_name',
                'personalization_status',
                'personalization_warnings',
            ]);
        });

        Schema::table('production_projects', function (Blueprint $table) {
            $table->dropIndex(['personalization_status']);
            $table->dropColumn([
                'template_hero_name',
                'template_hero_gender',
                'personalized_hero_name',
                'child_story_role',
                'personalization_status',
                'personalization_warnings',
            ]);
        });
    }
};

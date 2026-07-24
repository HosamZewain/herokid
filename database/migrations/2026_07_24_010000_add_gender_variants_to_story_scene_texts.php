<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_scene_templates', function (Blueprint $table): void {
            $table->longText('alternate_text_template')->nullable()->after('text_template');
        });

        Schema::table('order_scene_text_snapshots', function (Blueprint $table): void {
            $table->string('selected_text_variant', 30)->nullable()->after('rendered_text');
        });
    }

    public function down(): void
    {
        Schema::table('order_scene_text_snapshots', function (Blueprint $table): void {
            $table->dropColumn('selected_text_variant');
        });

        Schema::table('story_scene_templates', function (Blueprint $table): void {
            $table->dropColumn('alternate_text_template');
        });
    }
};

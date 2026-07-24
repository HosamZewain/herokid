<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_scene_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('scene_number');
            $table->string('title')->nullable();
            $table->longText('text_template')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'scene_number']);
            $table->index(['story_id', 'scene_number']);
        });

        Schema::create('order_scene_text_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_story_scene_template_id')->nullable();
            $table->unsignedTinyInteger('scene_number');
            $table->string('title_snapshot')->nullable();
            $table->longText('template_text_snapshot')->nullable();
            $table->longText('rendered_text')->nullable();
            $table->json('render_context_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'scene_number']);
            $table->index(['order_id', 'scene_number']);
            $table->foreign('source_story_scene_template_id', 'order_scene_snapshot_template_fk')
                ->references('id')
                ->on('story_scene_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_scene_text_snapshots');
        Schema::dropIfExists('story_scene_templates');
    }
};

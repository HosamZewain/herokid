<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->boolean('applies_to_all_stories')->default(true)->after('story_count');
        });

        Schema::create('pricing_package_story', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pricing_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pricing_package_id', 'story_id']);
            $table->index(['story_id', 'pricing_package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_package_story');

        Schema::table('pricing_packages', function (Blueprint $table): void {
            $table->dropColumn('applies_to_all_stories');
        });
    }
};

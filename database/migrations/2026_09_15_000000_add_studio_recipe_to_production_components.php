<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_production_components', function (Blueprint $table): void {
            $table->boolean('studio_enabled')->default(false)->after('is_active');
            $table->string('studio_workflow', 80)->nullable()->after('studio_enabled');
            $table->unsignedInteger('studio_recipe_version')->nullable()->after('studio_workflow');
            $table->json('studio_recipe')->nullable()->after('studio_recipe_version');
        });

        Schema::table('order_item_production_components', function (Blueprint $table): void {
            $table->boolean('studio_enabled')->default(false)->after('sort_order');
            $table->string('studio_workflow', 80)->nullable()->after('studio_enabled');
            $table->unsignedInteger('studio_recipe_version')->nullable()->after('studio_workflow');
            $table->json('studio_recipe')->nullable()->after('studio_recipe_version');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_production_components', function (Blueprint $table): void {
            $table->dropColumn(['studio_enabled', 'studio_workflow', 'studio_recipe_version', 'studio_recipe']);
        });

        Schema::table('product_production_components', function (Blueprint $table): void {
            $table->dropColumn(['studio_enabled', 'studio_workflow', 'studio_recipe_version', 'studio_recipe']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_production_components', 'order_item_production_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('production_stage', 32)->default('standard');
            });
        }
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('book_page_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('book_page_count'));
        foreach (['product_production_components', 'order_item_production_components'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('production_stage'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_upsell_rules', function (Blueprint $table): void {
            $table->foreignId('source_product_id')
                ->nullable()
                ->after('target_product_id')
                ->constrained('products')
                ->nullOnDelete();
            $table->index(['source_product_id', 'is_active', 'priority'], 'upsell_source_product_active_priority');
        });
    }

    public function down(): void
    {
        Schema::table('product_upsell_rules', function (Blueprint $table): void {
            $table->dropIndex('upsell_source_product_active_priority');
            $table->dropConstrainedForeignId('source_product_id');
        });
    }
};

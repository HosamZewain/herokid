<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cover original purchase-date grouping, including soft-deleted history.
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['checkout_group_key', 'created_at', 'id'], 'orders_checkout_intake_reporting_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_checkout_intake_reporting_idx');
        });
    }
};

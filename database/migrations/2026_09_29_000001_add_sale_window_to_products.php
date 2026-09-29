<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dateTime('sale_starts_at')->nullable()->after('sale_price_cents');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');
            $table->index(['sale_starts_at', 'sale_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['sale_starts_at', 'sale_ends_at']);
            $table->dropColumn(['sale_starts_at', 'sale_ends_at']);
        });
    }
};

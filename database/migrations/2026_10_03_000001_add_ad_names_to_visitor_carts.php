<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_carts', function (Blueprint $table): void {
            $table->string('ad_name')->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('adset_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_carts', function (Blueprint $table): void {
            $table->dropColumn(['ad_name', 'campaign_name', 'adset_name']);
        });
    }
};

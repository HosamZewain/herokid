<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_carts', function (Blueprint $table): void {
            $table->string('utm_content')->nullable()->after('utm_campaign');
            $table->string('utm_term')->nullable()->after('utm_content');
            $table->string('campaign_id')->nullable()->after('utm_term');
            $table->string('adset_id')->nullable()->after('campaign_id');
            $table->string('ad_id')->nullable()->after('adset_id');
            $table->string('fbclid', 512)->nullable()->after('ad_id');
            $table->text('landing_url')->nullable()->after('fbclid');
            $table->text('referrer')->nullable()->after('landing_url');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_carts', function (Blueprint $table): void {
            $table->dropColumn([
                'utm_content',
                'utm_term',
                'campaign_id',
                'adset_id',
                'ad_id',
                'fbclid',
                'landing_url',
                'referrer',
            ]);
        });
    }
};

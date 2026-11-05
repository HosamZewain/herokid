<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_carts', function (Blueprint $table): void {
            $table->string('ttclid', 512)->nullable();
        });
        Schema::create('storefront_visits', function (Blueprint $table): void {
            $table->id();
            $table->char('visit_key', 64)->unique();
            $table->string('source_type', 32);
            $table->string('source', 255)->nullable();
            $table->string('medium', 255)->nullable();
            $table->string('campaign', 255)->nullable();
            $table->string('landing_path', 512);
            $table->timestamp('started_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_visits');
        Schema::table('visitor_carts', fn (Blueprint $table) => $table->dropColumn('ttclid'));
    }
};

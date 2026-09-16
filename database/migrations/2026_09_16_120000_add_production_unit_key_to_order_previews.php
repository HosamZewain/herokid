<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_previews', function (Blueprint $table): void {
            $table->string('production_unit_key', 120)->nullable()->after('order_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_previews', function (Blueprint $table): void {
            $table->dropIndex(['production_unit_key']);
            $table->dropColumn('production_unit_key');
        });
    }
};

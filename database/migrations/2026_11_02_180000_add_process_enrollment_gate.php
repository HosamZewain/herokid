<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_automations', function (Blueprint $table) {
            $table->boolean('accepts_new_runs')->default(true);
        });
        Schema::table('order_item_production_components', fn (Blueprint $table) => $table->timestamp('retired_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('order_automations', fn (Blueprint $table) => $table->dropColumn('accepts_new_runs'));
        Schema::table('order_item_production_components', fn (Blueprint $table) => $table->dropColumn('retired_at'));
    }
};

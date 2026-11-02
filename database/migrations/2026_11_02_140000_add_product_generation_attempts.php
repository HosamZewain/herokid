<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_generations', function (Blueprint $table) {
            $table->unsignedInteger('attempt_number')->default(1);
            $table->unsignedBigInteger('previous_run_id')->nullable()->unique();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('active_item_id')->nullable()->unique();
            $table->string('handoff_token', 64)->nullable();
            $table->unique(['order_item_id', 'attempt_number']);
            $table->dropUnique(['order_item_id']);
        });
        DB::table('puzzle_generations')->whereIn('status', ['queued', 'processing', 'uncertain'])
            ->update(['active_item_id' => DB::raw('order_item_id')]);
    }

    public function down(): void
    {
        // Never discard paid attempt history to restore the old one-row-per-item schema.
        if (DB::table('puzzle_generations')->select('order_item_id')->groupBy('order_item_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Product attempts exist; preserve history and forward-fix.');
        }
        Schema::table('puzzle_generations', function (Blueprint $table) {
            $table->dropUnique(['order_item_id', 'attempt_number']);
            $table->dropUnique(['previous_run_id']);
            $table->dropUnique(['active_item_id']);
            $table->dropColumn(['attempt_number', 'previous_run_id', 'requested_by', 'active_item_id', 'handoff_token']);
            $table->unique('order_item_id');
        });
    }
};

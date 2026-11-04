<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Native admission still refuses any prior task. Only the explicit story
        // revision gate can fork/reuse history, serialized under checkout locks.
        Schema::table('order_process_identities', function (Blueprint $table) {
            $table->index('order_id', 'process_identity_order_history');
            $table->index('attempt_id', 'process_identity_attempt_history');
            $table->unique(['run_id', 'order_id'], 'process_identity_run_order_unique');
            $table->unique(['run_id', 'attempt_id'], 'process_identity_run_attempt_unique');
        });
        Schema::table('order_process_identities', function (Blueprint $table) {
            $table->dropUnique('order_process_identities_order_id_unique');
            $table->dropUnique('order_process_identities_attempt_id_unique');
        });
    }

    public function down(): void
    {
        foreach (['order_id', 'attempt_id'] as $column) {
            if (DB::table('order_process_identities')->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Retain story revision identity history; rollback requires a compatible reader.');
            }
        }
        Schema::table('order_process_identities', function (Blueprint $table) {
            $table->unique('order_id');
            $table->unique('attempt_id');
            $table->dropUnique('process_identity_run_order_unique');
            $table->dropUnique('process_identity_run_attempt_unique');
            $table->dropIndex('process_identity_order_history');
            $table->dropIndex('process_identity_attempt_history');
        });
    }
};

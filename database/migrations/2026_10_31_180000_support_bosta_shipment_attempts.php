<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bosta_shipments', function (Blueprint $table): void {
            $table->string('active_checkout_key')->nullable()->unique();
            $table->timestamp('superseded_at')->nullable();
            $table->string('replacement_reason', 500)->nullable();
            $table->string('cancellation_status')->nullable();
            $table->index('checkout_group_key');
            $table->dropUnique(['checkout_group_key']);
        });
        DB::table('bosta_shipments')->whereNull('deleted_at')->update(['active_checkout_key' => DB::raw('checkout_group_key')]);
        Schema::table('bosta_pickups', function (Blueprint $table): void {
            $table->string('request_key')->nullable()->unique();
            $table->string('active_slot_key')->nullable()->unique();
            $table->string('last_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('bosta_shipments')->select('checkout_group_key')->groupBy('checkout_group_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Shipment history exists; do not roll back this schema or delete shipment attempts.');
        }
        Schema::table('bosta_pickups', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropUnique(['active_slot_key']);
            $table->dropColumn(['request_key', 'active_slot_key', 'last_error']);
        });
        Schema::table('bosta_shipments', function (Blueprint $table): void {
            $table->dropUnique(['active_checkout_key']);
            $table->dropColumn(['active_checkout_key', 'superseded_at', 'replacement_reason', 'cancellation_status']);
            $table->dropIndex(['checkout_group_key']);
            $table->unique('checkout_group_key');
        });
    }
};

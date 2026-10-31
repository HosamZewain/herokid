<?php

use App\Support\OrderStatusRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('order_status_definitions')->where('type', 'order')->where('key', 'new')
                ->update(['label_ar' => 'طلب جديد غير مؤكد', 'updated_at' => now()]);
            DB::table('order_status_definitions')->insertOrIgnore([
                'type' => 'order', 'key' => 'new_confirmed', 'label_ar' => 'طلب جديد مؤكد',
                'color' => 'emerald', 'behavior' => 'standard', 'sort_order' => 15,
                'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        // Forward-only reference data: historical order/log references must survive code rollback.
        // Disable sending and roll code back without deleting this status or rewriting orders.
    }
};

<?php

use App\Support\OrderStatusRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_status_definitions')) {
            return;
        }

        $exists = DB::table('order_status_definitions')
            ->where('type', OrderStatusRegistry::TYPE_SHIPPING)
            ->where('key', 'shipment_created')
            ->exists();

        if (! $exists) {
            DB::table('order_status_definitions')->insert([
                'type' => OrderStatusRegistry::TYPE_SHIPPING,
                'key' => 'shipment_created',
                'label_ar' => 'تم إنشاء شحنة',
                'description' => 'تم إنشاء الشحنة لدى شركة Bosta ولم تُضف إلى طلب استلام بعد.',
                'color' => 'indigo',
                'behavior' => 'shipment_created',
                'sort_order' => 35,
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        // Keep a status that may already be referenced by production orders and logs.
    }
};

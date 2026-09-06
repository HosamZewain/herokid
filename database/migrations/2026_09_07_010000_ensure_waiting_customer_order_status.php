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
            ->where('type', OrderStatusRegistry::TYPE_ORDER)
            ->where('key', 'waiting_customer')
            ->exists();

        if (! $exists) {
            DB::table('order_status_definitions')->insert([
                'type' => OrderStatusRegistry::TYPE_ORDER,
                'key' => 'waiting_customer',
                'label_ar' => 'بانتظار العميل',
                'description' => 'اكتملت هوية الطفل والطلب بانتظار مراجعة أو موافقة العميل قبل بدء الإنتاج.',
                'color' => 'amber',
                'behavior' => 'standard',
                'sort_order' => 28,
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
        // Preserve this status because installations may already use it operationally.
    }
};

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
            ->where('key', 'ready_preview')
            ->exists();

        if (! $exists) {
            DB::table('order_status_definitions')->insert([
                'type' => OrderStatusRegistry::TYPE_ORDER,
                'key' => 'ready_preview',
                'label_ar' => 'جاهز للمعاينة',
                'description' => 'اكتمل الإنتاج وأصبح الطلب جاهزًا لإرسال المعاينة للعميل.',
                'color' => 'purple',
                'behavior' => 'standard',
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
        // Preserve the status because production may have created and used it before this migration.
    }
};

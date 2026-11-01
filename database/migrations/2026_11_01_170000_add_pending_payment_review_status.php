<?php

use App\Support\OrderStatusRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_status_definitions')->insertOrIgnore([
            'type' => 'payment', 'key' => 'pending_review', 'label_ar' => 'بانتظار مراجعة الدفع',
            'color' => 'orange', 'behavior' => 'unpaid', 'sort_order' => 15,
            'is_active' => true, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        // Preserve referenced financial status/history on code rollback.
    }
};

<?php

use App\Support\OrderStatusRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pending_confirmation` sits before `new` so the Agent API cannot acquire a
     * checkout the customer has not confirmed. `identity_pending_confirmation`
     * parks an order while the parent reviews the generated child identity.
     *
     * Both are inactive on install. Enabling the matching RoboDesk action is
     * what puts them into circulation, so nothing changes for a deployment that
     * never turns the integration on.
     */
    private const STATUSES = [
        [
            'key' => 'pending_confirmation',
            'label_ar' => 'بانتظار تأكيد العميل',
            'description' => 'الطلب في انتظار تأكيد العميل عبر واتساب قبل بدء الإنتاج.',
            'color' => 'sky',
            'sort_order' => 5,
        ],
        [
            'key' => 'identity_pending_confirmation',
            'label_ar' => 'بانتظار اعتماد الهوية',
            'description' => 'تم إنشاء هوية الطفل وبانتظار موافقة العميل أو ملاحظاته.',
            'color' => 'fuchsia',
            'sort_order' => 15,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('order_status_definitions')) {
            return;
        }

        foreach (self::STATUSES as $status) {
            DB::table('order_status_definitions')->updateOrInsert(
                ['type' => OrderStatusRegistry::TYPE_ORDER, 'key' => $status['key']],
                [
                    'label_ar' => $status['label_ar'],
                    'description' => $status['description'],
                    'color' => $status['color'],
                    'behavior' => 'standard',
                    'sort_order' => $status['sort_order'],
                    'is_active' => true,
                    'is_system' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_status_definitions')) {
            return;
        }

        DB::table('order_status_definitions')
            ->where('type', OrderStatusRegistry::TYPE_ORDER)
            ->whereIn('key', array_column(self::STATUSES, 'key'))
            ->delete();

        OrderStatusRegistry::clearCache();
    }
};

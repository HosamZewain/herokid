<?php

use App\Support\AdminPermissionRegistry;
use App\Support\OrderStatusRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'settings.order_statuses.manage';

    public function up(): void
    {
        if (! Schema::hasTable('order_status_definitions')) {
            Schema::create('order_status_definitions', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 20)->index();
                $table->string('key', 32);
                $table->string('label_ar', 100);
                $table->string('description', 500)->nullable();
                $table->string('color', 20)->default('slate');
                $table->string('behavior', 40)->default('standard')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('is_system')->default(false);
                $table->timestamps();
                $table->unique(['type', 'key']);
                $table->index(['type', 'is_active', 'sort_order'], 'order_status_type_active_sort_idx');
            });
        }

        $sortOrders = [];
        foreach (OrderStatusRegistry::fallbackDefinitions() as $definition) {
            $type = $definition[0];
            $sortOrders[$type] = ($sortOrders[$type] ?? 0) + 10;
            DB::table('order_status_definitions')->updateOrInsert([
                'type' => $type,
                'key' => $definition[1],
            ], [
                'label_ar' => $definition[2],
                'color' => $definition[3],
                'behavior' => $definition[4],
                'sort_order' => $sortOrders[$type],
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->syncPermission();
        OrderStatusRegistry::clearCache();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_definitions');

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($permissionId && Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('key', self::PERMISSION)->delete();
        }
    }

    private function syncPermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definition = AdminPermissionRegistry::metadata(self::PERMISSION) ?? [
            'group_key' => 'settings',
            'name_ar' => 'إدارة حالات الطلبات',
            'name_en' => 'Manage order statuses',
            'description_ar' => 'إضافة وتعديل وتعطيل حالات الطلب والدفع والطباعة والشحن.',
            'description_en' => 'Manage order, payment, printing, and shipping statuses.',
            'sort_order' => 975,
        ];
        DB::table('permissions')->updateOrInsert(['key' => self::PERMISSION], [
            'group_key' => $definition['group_key'],
            'name_ar' => $definition['name_ar'],
            'name_en' => $definition['name_en'],
            'description_ar' => $definition['description_ar'],
            'description_en' => $definition['description_en'],
            'sort_order' => $definition['sort_order'],
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
        DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id')
            ->each(fn (int $userId) => DB::table('permission_user')->insertOrIgnore([
                'permission_id' => $permissionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }
};

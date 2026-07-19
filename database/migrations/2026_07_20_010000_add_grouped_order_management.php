<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('checkout_group_key')->nullable()->after('order_number')->index();
            $table->foreignId('deleted_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by_user_id');
            $table->softDeletes()->index();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->timestamp('stock_released_at')->nullable()->after('fulfillment_status')->index();
            $table->foreignId('stock_released_by_user_id')->nullable()->after('stock_released_at')->constrained('users')->nullOnDelete();
        });

        DB::table('orders')
            ->select(['id', 'delivery_details'])
            ->orderBy('id')
            ->chunkById(200, function ($orders): void {
                foreach ($orders as $order) {
                    $delivery = json_decode((string) $order->delivery_details, true) ?: [];
                    $key = trim((string) ($delivery['checkout_group'] ?? '')) ?: 'ORDER-'.$order->id;

                    DB::table('orders')->where('id', $order->id)->update(['checkout_group_key' => $key]);
                }
            });

        $this->syncPermission();
    }

    public function down(): void
    {
        $permissionId = Schema::hasTable('permissions')
            ? DB::table('permissions')->where('key', 'orders.delete')->value('id')
            : null;

        if ($permissionId && Schema::hasTable('permission_user')) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'orders.delete')->delete();
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_released_by_user_id');
            $table->dropColumn('stock_released_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropSoftDeletes();
            $table->dropColumn(['checkout_group_key', 'deletion_reason']);
        });
    }

    private function syncPermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definition = AdminPermissionRegistry::metadata('orders.delete');
        if (! $definition) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['key' => 'orders.delete'],
            [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 999,
                'is_system' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', 'orders.delete')->value('id');

        DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->each(fn (int $userId) => DB::table('permission_user')->insertOrIgnore([
                'permission_id' => $permissionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }
};

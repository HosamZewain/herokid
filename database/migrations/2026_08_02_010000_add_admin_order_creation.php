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
            $table->unsignedInteger('discount_cents')->default(0)->after('checkout_group_key');
            $table->text('discount_reason')->nullable()->after('discount_cents');
            $table->foreignId('created_by_admin_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('order_source')->default('website')->after('created_by_admin_id')->index();
            $table->text('source_notes')->nullable()->after('order_source');
        });

        $this->syncPermission();
    }

    public function down(): void
    {
        $permissionId = Schema::hasTable('permissions')
            ? DB::table('permissions')->where('key', 'orders.create')->value('id')
            : null;

        if ($permissionId && Schema::hasTable('permission_user')) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('key', 'orders.create')->delete();
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by_admin_id');
            $table->dropIndex(['order_source']);
            $table->dropColumn(['discount_cents', 'discount_reason', 'order_source', 'source_notes']);
        });
    }

    private function syncPermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definition = AdminPermissionRegistry::metadata('orders.create');

        DB::table('permissions')->updateOrInsert(
            ['key' => 'orders.create'],
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

        $permissionId = DB::table('permissions')->where('key', 'orders.create')->value('id');

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

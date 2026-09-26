<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'store.discount_codes.view',
        'store.discount_codes.manage',
    ];

    public function up(): void
    {
        Schema::table('mobile_promo_codes', function (Blueprint $table): void {
            $table->boolean('website_enabled')->default(false)->after('is_active')->index();
            $table->boolean('mobile_enabled')->default(true)->after('website_enabled')->index();
        });

        Schema::create('website_promo_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_promo_code_id')->constrained()->restrictOnDelete();
            $table->string('checkout_group_key')->unique();
            $table->string('customer_phone_hash', 64)->index();
            $table->unsignedInteger('discount_cents');
            $table->timestamps();
            $table->index(['mobile_promo_code_id', 'customer_phone_hash'], 'website_promo_customer_idx');
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
        }

        Schema::dropIfExists('website_promo_code_redemptions');

        Schema::table('mobile_promo_codes', function (Blueprint $table): void {
            $table->dropColumn(['website_enabled', 'mobile_enabled']);
        });
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (self::PERMISSIONS as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            if (! $definition) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 999,
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $managerPermissionId = DB::table('permissions')
            ->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->value('id');
        if (! $managerPermissionId) {
            return;
        }

        $managerIds = DB::table('users')
            ->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->where('users.role', 'admin')
            ->where('users.is_active', true)
            ->where('permission_user.permission_id', $managerPermissionId)
            ->pluck('users.id');

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        foreach ($managerIds as $managerId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $managerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};

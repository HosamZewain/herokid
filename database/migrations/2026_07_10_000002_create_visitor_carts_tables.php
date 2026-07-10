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
        Schema::create('visitor_carts', function (Blueprint $table) {
            $table->id();
            $table->uuid('cart_identifier')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_hash', 80)->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('currency', 3)->default('EGP');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedBigInteger('items_subtotal_cents')->default(0);
            $table->unsignedBigInteger('cart_total_cents')->default(0);
            $table->timestamp('first_added_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->foreignId('related_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('utm_source')->nullable()->index();
            $table->string('utm_medium')->nullable()->index();
            $table->string('utm_campaign')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('visitor_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_cart_id')->constrained('visitor_carts')->cascadeOnDelete();
            $table->string('cart_item_key')->index();
            $table->string('item_type', 40)->index();
            $table->foreignId('story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('title_snapshot');
            $table->string('variant_snapshot')->nullable();
            $table->string('package_snapshot')->nullable();
            $table->string('linked_cart_item_key')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('total_price_cents')->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->json('item_snapshot')->nullable();
            $table->timestamp('first_added_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('removed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['visitor_cart_id', 'cart_item_key']);
            $table->index(['visitor_cart_id', 'removed_at']);
        });

        Schema::create('visitor_cart_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_cart_id')->constrained('visitor_carts')->cascadeOnDelete();
            $table->foreignId('visitor_cart_item_id')->nullable()->constrained('visitor_cart_items')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50)->index();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['visitor_cart_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        $this->syncPermission();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('key', 'visitor_carts.view')->value('id');

            if ($permissionId && Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }

            DB::table('permissions')->where('key', 'visitor_carts.view')->delete();
        }

        Schema::dropIfExists('visitor_cart_activities');
        Schema::dropIfExists('visitor_cart_items');
        Schema::dropIfExists('visitor_carts');
    }

    private function syncPermission(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definition = AdminPermissionRegistry::metadata('visitor_carts.view');
        if (! $definition) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['key' => 'visitor_carts.view'],
            [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 999,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', 'visitor_carts.view')->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $userId) use ($permissionId): void {
                DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};

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
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'slug']);
        });

        Schema::create('expense_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->index();
            $table->date('transaction_date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('EGP');
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('payment_method', 50)->nullable()->index();
            $table->string('vendor_name')->nullable()->index();
            $table->string('reference_number')->nullable()->index();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('status', 20)->default('posted')->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type', 'transaction_date'], 'expense_transactions_summary_index');
        });

        Schema::create('expense_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained('expense_transactions')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->text('description');
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $now = now();
        $categories = [
            ['type' => 'income', 'name' => 'رصيد افتتاحي', 'slug' => 'opening-balance', 'sort_order' => 10],
            ['type' => 'income', 'name' => 'تمويل من المالك', 'slug' => 'owner-funding', 'sort_order' => 20],
            ['type' => 'income', 'name' => 'مبيعات', 'slug' => 'sales', 'sort_order' => 30],
            ['type' => 'income', 'name' => 'استرداد مصروف', 'slug' => 'expense-refund', 'sort_order' => 40],
            ['type' => 'income', 'name' => 'أخرى', 'slug' => 'other-income', 'sort_order' => 50],
            ['type' => 'expense', 'name' => 'أحبار وطباعة', 'slug' => 'printing-ink', 'sort_order' => 10],
            ['type' => 'expense', 'name' => 'ورق وخامات', 'slug' => 'paper-materials', 'sort_order' => 20],
            ['type' => 'expense', 'name' => 'تغليف', 'slug' => 'packaging', 'sort_order' => 30],
            ['type' => 'expense', 'name' => 'شحن وتوصيل', 'slug' => 'shipping-delivery', 'sort_order' => 40],
            ['type' => 'expense', 'name' => 'إعلانات وتسويق', 'slug' => 'advertising-marketing', 'sort_order' => 50],
            ['type' => 'expense', 'name' => 'أدوات ومعدات', 'slug' => 'tools-equipment', 'sort_order' => 60],
            ['type' => 'expense', 'name' => 'تصميم ومحتوى', 'slug' => 'design-content', 'sort_order' => 70],
            ['type' => 'expense', 'name' => 'ذكاء اصطناعي', 'slug' => 'artificial-intelligence', 'sort_order' => 80],
            ['type' => 'expense', 'name' => 'صيانة', 'slug' => 'maintenance', 'sort_order' => 90],
            ['type' => 'expense', 'name' => 'مصاريف تشغيلية', 'slug' => 'operating-expenses', 'sort_order' => 100],
            ['type' => 'expense', 'name' => 'أخرى', 'slug' => 'other-expense', 'sort_order' => 110],
        ];

        DB::table('expense_categories')->insert(array_map(
            fn (array $category): array => $category + [
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $categories,
        ));

        $this->syncPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $keys = array_keys($this->permissionDefinitions());
            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id');

            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
            }

            DB::table('permissions')->whereIn('key', $keys)->delete();
        }

        Schema::dropIfExists('expense_activity_logs');
        Schema::dropIfExists('expense_transactions');
        Schema::dropIfExists('expense_categories');
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissionDefinitions() as $key => $fallback) {
            $definition = AdminPermissionRegistry::metadata($key) ?? $fallback;
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'group_key' => $definition['group_key'],
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'description_ar' => $definition['description_ar'] ?? null,
                    'description_en' => $definition['description_en'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 999,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys($this->permissionDefinitions()))
            ->pluck('id');

        DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $userId) use ($permissionIds): void {
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_user')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    private function permissionDefinitions(): array
    {
        $names = [
            'expenses.view' => ['عرض المصروفات', 'View expenses'],
            'expenses.create_income' => ['إضافة الوارد', 'Create income'],
            'expenses.create_expense' => ['إضافة المصروفات', 'Create expenses'],
            'expenses.edit' => ['تعديل العمليات المالية', 'Edit expense transactions'],
            'expenses.void' => ['إلغاء العمليات المالية', 'Void expense transactions'],
            'expenses.view_attachments' => ['عرض مرفقات المصروفات', 'View expense attachments'],
            'expenses.download_attachments' => ['تنزيل مرفقات المصروفات', 'Download expense attachments'],
            'expenses.manage_categories' => ['إدارة تصنيفات المصروفات', 'Manage expense categories'],
            'expenses.export' => ['تصدير المصروفات', 'Export expenses'],
            'expenses.view_reports' => ['عرض تقارير المصروفات', 'View expense reports'],
        ];

        $definitions = [];
        foreach ($names as $index => $name) {
            $definitions[$index] = [
                'group_key' => 'expenses',
                'name_ar' => $name[0],
                'name_en' => $name[1],
                'description_ar' => 'صلاحية خاصة بوحدة المصروفات اليدوية.',
                'description_en' => 'Permission for the manual expenses ledger.',
                'sort_order' => (array_search($index, array_keys($names), true) + 1) * 10,
            ];
        }

        return $definitions;
    }
};

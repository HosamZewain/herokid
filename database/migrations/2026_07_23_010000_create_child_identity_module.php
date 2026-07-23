<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'child_identities.view',
        'child_identities.view_media',
        'child_identities.view_costs',
        'child_identities.generate',
        'child_identities.approve',
        'child_identities.delete',
        'child_identities.restore',
        'child_identities.force_delete',
        'child_identities.settings',
    ];

    public function up(): void
    {
        Schema::create('child_identity_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resume_token_hash', 64);
            $table->string('parent_name');
            $table->string('parent_phone', 50);
            $table->string('parent_email')->nullable();
            $table->string('child_name');
            $table->unsignedTinyInteger('child_age');
            $table->string('age_range', 100);
            $table->string('gender', 20)->nullable();
            $table->string('status', 40)->default('incomplete')->index();
            $table->unsignedBigInteger('approved_attempt_id')->nullable();
            $table->foreignId('selected_story_category_id')->nullable()->constrained('story_categories')->nullOnDelete();
            $table->foreignId('selected_story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('consent_accepted_at');
            $table->string('consent_version', 40);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->text('referrer')->nullable();
            $table->unsignedInteger('total_attempts')->default(0);
            $table->unsignedInteger('successful_attempts')->default(0);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->decimal('total_cost_usd', 12, 6)->nullable();
            $table->decimal('total_cost_egp', 12, 4)->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('converted_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'status']);
        });

        Schema::create('child_identity_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_identity_request_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('upload_status', 30)->default('uploaded');
            $table->string('validation_status', 30)->default('valid');
            $table->text('validation_notes')->nullable();
            $table->timestamps();
            $table->index(['child_identity_request_id', 'validation_status'], 'ci_photos_request_validation_idx');
        });

        Schema::create('child_identity_generation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('child_identity_request_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('idempotency_key');
            $table->string('initiated_by', 20)->default('customer');
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('provider', 50)->default('openai');
            $table->string('model', 120);
            $table->string('prompt_version', 80);
            $table->longText('prompt_snapshot');
            $table->string('prompt_hash', 64);
            $table->unsignedTinyInteger('input_photos_count');
            $table->string('image_size', 30);
            $table->string('image_quality', 30);
            $table->string('api_request_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('output_disk', 50)->nullable();
            $table->string('output_storage_path')->nullable();
            $table->string('preview_storage_path')->nullable();
            $table->string('output_checksum', 64)->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->decimal('usd_to_egp_rate', 12, 6)->nullable();
            $table->decimal('cost_egp', 12, 4)->nullable();
            $table->string('cost_calculation_method', 30)->default('unknown');
            $table->string('billing_status', 30)->default('unknown');
            $table->string('error_code', 100)->nullable();
            $table->text('safe_error_message')->nullable();
            $table->longText('technical_error')->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();
            $table->unique(['child_identity_request_id', 'attempt_number'], 'ci_attempt_request_number_unique');
            $table->unique(['child_identity_request_id', 'idempotency_key'], 'ci_attempt_request_idempotency_unique');
            $table->foreign('child_identity_request_id', 'ci_attempt_request_fk')
                ->references('id')->on('child_identity_requests')->cascadeOnDelete();
            $table->foreign('initiated_by_user_id', 'ci_attempt_initiator_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('child_identity_attempt_photos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('child_identity_generation_attempt_id');
            $table->unsignedBigInteger('child_identity_photo_id');
            $table->string('disk', 50);
            $table->string('path');
            $table->string('checksum', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(
                ['child_identity_generation_attempt_id', 'child_identity_photo_id'],
                'ci_attempt_photo_unique'
            );
            $table->foreign('child_identity_generation_attempt_id', 'ci_attempt_photo_attempt_fk')
                ->references('id')->on('child_identity_generation_attempts')->cascadeOnDelete();
            $table->foreign('child_identity_photo_id', 'ci_attempt_photo_photo_fk')
                ->references('id')->on('child_identity_photos')->restrictOnDelete();
        });

        Schema::create('child_identity_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('child_identity_request_id');
            $table->unsignedBigInteger('child_identity_generation_attempt_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 30)->default('customer');
            $table->string('source', 40)->default('web');
            $table->string('event_type', 100)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['child_identity_request_id', 'created_at'], 'ci_events_request_created_idx');
            $table->foreign('child_identity_request_id', 'ci_event_request_fk')
                ->references('id')->on('child_identity_requests')->cascadeOnDelete();
            $table->foreign('child_identity_generation_attempt_id', 'ci_event_attempt_fk')
                ->references('id')->on('child_identity_generation_attempts')->nullOnDelete();
        });

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->foreign('approved_attempt_id', 'ci_request_approved_attempt_fk')
                ->references('id')
                ->on('child_identity_generation_attempts')
                ->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('child_identity_request_id')
                ->nullable()
                ->after('story_id')
                ->constrained('child_identity_requests')
                ->nullOnDelete();
            $table->foreignId('child_identity_approved_attempt_id')
                ->nullable()
                ->after('child_identity_request_id')
                ->constrained('child_identity_generation_attempts')
                ->nullOnDelete();
        });

        $this->updateRetentionCopy();
        $this->syncPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('child_identity_approved_attempt_id');
            $table->dropConstrainedForeignId('child_identity_request_id');
        });

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->dropForeign('ci_request_approved_attempt_fk');
        });

        Schema::dropIfExists('child_identity_events');
        Schema::dropIfExists('child_identity_attempt_photos');
        Schema::dropIfExists('child_identity_generation_attempts');
        Schema::dropIfExists('child_identity_photos');
        Schema::dropIfExists('child_identity_requests');
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionDefinitions = (require config_path('admin_permissions.php'))['permissions'] ?? [];

        foreach (self::PERMISSIONS as $key) {
            $definition = $permissionDefinitions[$key] ?? AdminPermissionRegistry::metadata($key);

            if (! $definition) {
                continue;
            }

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
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_values(array_diff(self::PERMISSIONS, ['child_identities.force_delete'])))
            ->pluck('id');

        DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->each(function (int $userId) use ($permissionIds): void {
                $permissionIds->each(fn (int $permissionId) => DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            });
    }

    private function updateRetentionCopy(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'photo_delete_days')->delete();
        }

        if (Schema::hasTable('faqs')) {
            DB::table('faqs')
                ->where(function ($query): void {
                    $query->where('question', 'هل بيانات وصور أطفالنا في أمان؟')
                        ->orWhere(function ($legacy): void {
                            $legacy->where('answer', 'like', '%صور%')
                                ->where(function ($days): void {
                                    $days->where('answer', 'like', '%٩٠%')
                                        ->orWhere('answer', 'like', '%90%');
                                });
                        });
                })
                ->update([
                    'answer' => 'نعم، تُحفظ الصور في مساحة خاصة محمية ولا تُستخدم إلا لتنفيذ الخدمة والطلب المرتبط. قد تنتهي صلاحية الرفعات المؤقتة غير المرتبطة بطلب، أما أصول هويات الأطفال ومخرجاتها ومحاولاتها فتُحتفظ بها بأمان لدعم الطلب وسجل الخدمة ولا تُحذف تلقائياً. يتم الحذف الفعلي فقط بإجراء إداري مصرح أو طلب خصوصية مدعوم.',
                    'updated_at' => now(),
                ]);
        }
    }
};

<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'robodesk.view',
        'robodesk.manage',
        'robodesk.review_payments',
        'robodesk.view_media',
        'robodesk.retry',
    ];

    public function up(): void
    {
        Schema::create('checkout_customer_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->unique();
            $table->string('confirmation_status', 40)->default('pending')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('customer_comment')->nullable();
            $table->string('robodesk_contact_id')->nullable()->index();
            $table->string('robodesk_conversation_id')->nullable()->index();
            $table->string('payment_request_status', 40)->default('not_requested')->index();
            $table->timestamp('payment_requested_at')->nullable();
            $table->timestamp('last_customer_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('robodesk_integration_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('deduplication_key')->nullable()->unique();
            $table->string('direction', 20)->index();
            $table->string('event_type')->index();
            $table->string('aggregate_type', 50)->nullable();
            $table->string('aggregate_id')->nullable();
            $table->string('checkout_group_key')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['direction', 'status', 'available_at'], 'robodesk_events_delivery_idx');
        });

        Schema::create('order_customer_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('review_type', 30)->index();
            $table->string('version_reference')->default('current');
            $table->string('decision', 30)->default('pending')->index();
            $table->text('customer_comment')->nullable();
            $table->string('source', 30)->default('robodesk');
            $table->string('external_message_id')->nullable()->index();
            $table->string('external_conversation_id')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'review_type', 'version_reference'], 'order_customer_review_version_unique');
        });

        Schema::create('order_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('checkout_group_key')->index();
            $table->string('source', 30)->default('robodesk');
            $table->string('external_message_id')->nullable()->unique();
            $table->string('external_conversation_id')->nullable()->index();
            $table->string('sender_phone')->nullable();
            $table->string('disk', 50)->default('local');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_proofs');
        Schema::dropIfExists('order_customer_reviews');
        Schema::dropIfExists('robodesk_integration_events');
        Schema::dropIfExists('checkout_customer_workflows');

        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

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
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('permission_user')) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id')
            ->each(fn (int $userId) => $permissionIds->each(fn (int $permissionId) => DB::table('permission_user')->insertOrIgnore([
                'permission_id' => $permissionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ])));
    }
};

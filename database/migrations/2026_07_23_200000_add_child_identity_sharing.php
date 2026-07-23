<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'child_identities.manage_shares',
        'child_identities.view_share_report',
    ];

    public function up(): void
    {
        Schema::create('child_identity_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_identity_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('generation_attempt_id')->constrained('child_identity_generation_attempts')->restrictOnDelete();
            $table->string('public_token', 64)->unique();
            $table->string('status', 30)->default('generating')->index();
            $table->boolean('share_enabled')->default(false)->index();
            $table->boolean('display_child_first_name')->default(false);
            $table->timestamp('consent_accepted_at');
            $table->string('consent_version', 80);
            $table->string('created_by_type', 30);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_session_hash', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('card_disk', 50)->default('local');
            $table->string('feed_card_path')->nullable();
            $table->string('story_card_path')->nullable();
            $table->string('og_card_path')->nullable();
            $table->string('template_version', 80);
            $table->string('card_fingerprint', 64)->nullable();
            $table->string('generated_fingerprint', 64)->nullable();
            $table->unsignedInteger('generation_version')->default(1);
            $table->longText('caption_snapshot');
            $table->text('hashtags_snapshot');
            $table->text('generation_error')->nullable();
            $table->unsignedBigInteger('total_views')->default(0);
            $table->unsignedBigInteger('total_cta_clicks')->default(0);
            $table->unsignedBigInteger('total_identity_starts')->default(0);
            $table->unsignedBigInteger('total_identity_completions')->default(0);
            $table->unsignedBigInteger('total_orders')->default(0);
            $table->timestamp('cards_generated_at')->nullable();
            $table->timestamp('last_shared_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['share_enabled', 'status', 'created_at'], 'ci_shares_public_status_idx');
        });

        Schema::create('child_identity_share_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_identity_share_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('channel', 40)->nullable()->index();
            $table->string('anonymous_visitor_id', 64)->nullable()->index();
            $table->unsignedBigInteger('referred_child_identity_request_id')->nullable();
            $table->unsignedBigInteger('referred_order_id')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('referrer_host')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['child_identity_share_id', 'event_type', 'occurred_at'], 'ci_share_events_funnel_idx');
            $table->foreign('referred_child_identity_request_id', 'ci_share_event_identity_fk')
                ->references('id')->on('child_identity_requests')->nullOnDelete();
            $table->foreign('referred_order_id', 'ci_share_event_order_fk')
                ->references('id')->on('orders')->nullOnDelete();
        });

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('referred_by_child_identity_share_id')->nullable()->after('user_id');
            $table->foreign('referred_by_child_identity_share_id', 'ci_request_referral_share_fk')
                ->references('id')->on('child_identity_shares')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('referred_by_child_identity_share_id')->nullable()->after('user_id');
            $table->foreign('referred_by_child_identity_share_id', 'orders_referral_share_fk')
                ->references('id')->on('child_identity_shares')->nullOnDelete();
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign('orders_referral_share_fk');
            $table->dropColumn('referred_by_child_identity_share_id');
        });

        Schema::table('child_identity_requests', function (Blueprint $table): void {
            $table->dropForeign('ci_request_referral_share_fk');
            $table->dropColumn('referred_by_child_identity_share_id');
        });

        Schema::dropIfExists('child_identity_share_events');
        Schema::dropIfExists('child_identity_shares');
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definitions = (require config_path('admin_permissions.php'))['permissions'] ?? [];

        foreach (self::PERMISSIONS as $key) {
            $definition = $definitions[$key] ?? AdminPermissionRegistry::metadata($key);

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

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id')
            ->each(function (int $userId) use ($permissionIds): void {
                $permissionIds->each(fn (int $permissionId) => DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            });
    }
};

<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'settings.notifications.view',
        'settings.notifications.manage',
        'settings.notifications.manage_credentials',
        'settings.notifications.test',
        'settings.notifications.view_logs',
        'settings.notifications.manage_rules',
    ];

    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->string('display_name');
            $table->boolean('is_active')->default(false)->index();
            $table->json('settings_json')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->string('credential_type');
            $table->text('encrypted_value');
            $table->string('last_four', 8)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['notification_channel_id', 'credential_type'], 'notification_credentials_unique_type');
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->index();
            $table->string('channel_type')->index();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('severity')->default('info')->index();
            $table->json('recipients_json')->nullable();
            $table->json('thresholds_json')->nullable();
            $table->string('template_subject')->nullable();
            $table->text('template_body')->nullable();
            $table->timestamps();

            $table->unique(['event_key', 'channel_type'], 'notification_rules_event_channel_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->index();
            $table->string('channel_type')->index();
            $table->string('dedupe_key')->nullable()->index();
            $table->nullableMorphs('notifiable');
            $table->string('recipient');
            $table->string('status')->default('pending')->index();
            $table->json('payload_json')->nullable();
            $table->json('response_json')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['event_key', 'channel_type', 'notifiable_type', 'notifiable_id'], 'notification_deliveries_event_notifiable_index');
        });

        Schema::create('notification_event_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->index();
            $table->string('severity')->default('info')->index();
            $table->string('dedupe_key')->nullable()->index();
            $table->nullableMorphs('notifiable');
            $table->json('context_json')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'notifiable_type', 'notifiable_id'], 'notification_event_logs_notifiable_index');
        });

        $this->seedDefaults();
        $this->registerPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('notification_event_logs');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_credentials');
        Schema::dropIfExists('notification_channels');

        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', array_keys(config('admin_notifications.settings', [])))->delete();
        }
    }

    private function seedDefaults(): void
    {
        $now = now();

        foreach (config('admin_notifications.channels', []) as $channel) {
            DB::table('notification_channels')->updateOrInsert(
                ['type' => $channel['type']],
                [
                    'display_name' => $channel['display_name'],
                    'is_active' => false,
                    'settings_json' => json_encode([
                        'default_chat_id' => config('admin_notifications.telegram.legacy_default_chat_id'),
                        'additional_chat_ids' => [],
                        'last_test_status' => null,
                        'last_test_message' => null,
                        'last_test_at' => null,
                    ], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        foreach (config('admin_notifications.events', []) as $eventKey => $event) {
            DB::table('notification_rules')->updateOrInsert(
                ['event_key' => $eventKey, 'channel_type' => 'telegram'],
                [
                    'is_enabled' => (bool) ($event['default_enabled'] ?? false),
                    'severity' => $event['severity'] ?? 'info',
                    'thresholds_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('settings')) {
            foreach (config('admin_notifications.settings', []) as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissionKeys as $key) {
            $permission = AdminPermissionRegistry::metadata($key);

            if (! $permission) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'group_key' => $permission['group_key'],
                    'name_ar' => $permission['name_ar'],
                    'name_en' => $permission['name_en'],
                    'description_ar' => $permission['description_ar'] ?? null,
                    'description_en' => $permission['description_en'] ?? null,
                    'sort_order' => $permission['sort_order'] ?? 0,
                    'is_system' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $safeGrantKeys = array_values(array_diff($this->permissionKeys, ['settings.notifications.manage_credentials']));
        $safePermissionIds = DB::table('permissions')->whereIn('key', $safeGrantKeys)->pluck('id');
        $adminIds = DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id');

        foreach ($adminIds as $adminId) {
            foreach ($safePermissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $adminId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $credentialPermissionId = DB::table('permissions')
            ->where('key', 'settings.notifications.manage_credentials')
            ->value('id');
        $permissionManagerId = DB::table('permissions')
            ->where('key', 'admin_users.permissions.manage')
            ->value('id');

        if (! $credentialPermissionId || ! $permissionManagerId) {
            return;
        }

        $managerIds = DB::table('permission_user')
            ->where('permission_id', $permissionManagerId)
            ->pluck('user_id');

        foreach ($managerIds as $managerId) {
            DB::table('permission_user')->insertOrIgnore([
                'user_id' => $managerId,
                'permission_id' => $credentialPermissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

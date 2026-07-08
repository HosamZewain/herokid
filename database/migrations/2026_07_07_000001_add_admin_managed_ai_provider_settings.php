<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'settings.ai_providers.view',
        'settings.ai_providers.manage',
        'settings.ai_providers.manage_credentials',
        'settings.ai_providers.manage_models',
        'settings.ai_providers.test_connection',
        'settings.ai_providers.enable_disable',
        'settings.ai_providers.view_costs',
    ];

    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('credential_type')->default('api_key');
            $table->text('encrypted_value');
            $table->string('last_four', 8)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->string('last_test_message')->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'credential_type'], 'ai_provider_credentials_unique_type');
        });

        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'display_name')) {
                $table->string('display_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('ai_providers', 'is_configured')) {
                $table->boolean('is_configured')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('ai_providers', 'is_available')) {
                $table->boolean('is_available')->default(false)->after('is_configured');
            }
            if (! Schema::hasColumn('ai_providers', 'capabilities_json')) {
                $table->json('capabilities_json')->nullable()->after('configuration_reference');
            }
            if (! Schema::hasColumn('ai_providers', 'settings_json')) {
                $table->json('settings_json')->nullable()->after('capabilities_json');
            }
            if (! Schema::hasColumn('ai_providers', 'default_timeout_seconds')) {
                $table->unsignedInteger('default_timeout_seconds')->nullable()->after('settings_json');
            }
            if (! Schema::hasColumn('ai_providers', 'default_max_retries')) {
                $table->unsignedInteger('default_max_retries')->nullable()->after('default_timeout_seconds');
            }
            if (! Schema::hasColumn('ai_providers', 'last_health_check_at')) {
                $table->timestamp('last_health_check_at')->nullable()->after('default_max_retries');
            }
            if (! Schema::hasColumn('ai_providers', 'last_health_check_status')) {
                $table->string('last_health_check_status')->nullable()->after('last_health_check_at');
            }
            if (! Schema::hasColumn('ai_providers', 'last_health_check_message')) {
                $table->string('last_health_check_message')->nullable()->after('last_health_check_status');
            }
        });

        Schema::table('ai_models', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_models', 'capability')) {
                $table->string('capability')->nullable()->after('display_name')->index();
            }
            if (! Schema::hasColumn('ai_models', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active')->index();
            }
            if (! Schema::hasColumn('ai_models', 'estimated_cost_type')) {
                $table->string('estimated_cost_type')->default('estimated')->after('estimated_cost_per_output');
            }
            if (! Schema::hasColumn('ai_models', 'estimated_cost_amount')) {
                $table->decimal('estimated_cost_amount', 10, 4)->nullable()->after('estimated_cost_type');
            }
            if (! Schema::hasColumn('ai_models', 'estimated_cost_currency')) {
                $table->string('estimated_cost_currency', 3)->default('USD')->after('estimated_cost_amount');
            }
            if (! Schema::hasColumn('ai_models', 'cost_unit')) {
                $table->string('cost_unit')->default('per_image')->after('estimated_cost_currency');
            }
            if (! Schema::hasColumn('ai_models', 'configuration_json')) {
                $table->json('configuration_json')->nullable()->after('cost_unit');
            }
            if (! Schema::hasColumn('ai_models', 'notes')) {
                $table->text('notes')->nullable()->after('configuration_json');
            }
            if (! Schema::hasColumn('ai_models', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('notes')->index();
            }
        });

        $this->registerPermissions();
        $this->upgradeFalRecords();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('ai_provider_credentials');

        Schema::table('ai_models', function (Blueprint $table) {
            foreach (['capability', 'is_default', 'estimated_cost_type', 'estimated_cost_amount', 'estimated_cost_currency', 'cost_unit', 'configuration_json', 'notes', 'sort_order'] as $column) {
                if (Schema::hasColumn('ai_models', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ai_providers', function (Blueprint $table) {
            foreach (['display_name', 'is_configured', 'is_available', 'capabilities_json', 'settings_json', 'default_timeout_seconds', 'default_max_retries', 'last_health_check_at', 'last_health_check_status', 'last_health_check_message'] as $column) {
                if (Schema::hasColumn('ai_providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
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
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
        $adminIds = DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id');

        foreach ($adminIds as $adminId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $adminId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function upgradeFalRecords(): void
    {
        if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $now = now();

        DB::table('ai_providers')->updateOrInsert(
            ['driver' => 'fal'],
            [
                'name' => 'fal.ai',
                'display_name' => 'fal.ai',
                'configuration_reference' => 'Admin encrypted credential',
                'capabilities_json' => json_encode(['text_to_image', 'image_to_image', 'image_editing', 'upscaling']),
                'settings_json' => json_encode([
                    'default_models' => [
                        'character_sheet' => 'fal-ai/flux-kontext/dev',
                        'scene_generation' => 'fal-ai/flux-kontext/dev',
                        'cover_generation' => 'fal-ai/flux-pro/kontext',
                        'premium_retry' => 'fal-ai/flux-pro/kontext',
                    ],
                ]),
                'default_timeout_seconds' => 180,
                'default_max_retries' => 2,
                'supports_text_to_image' => true,
                'supports_image_to_image' => true,
                'supports_editing' => true,
                'supports_upscaling' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $providerId = DB::table('ai_providers')->where('driver', 'fal')->value('id');

        foreach ([
            [
                'code' => 'fal-ai/flux-kontext/dev',
                'display_name' => 'FLUX Kontext Dev',
                'capability' => 'scene_generation',
                'capabilities' => ['character_sheet', 'scene_generation', 'image_editing'],
                'cost' => '0.0300',
                'notes' => 'Normal scene generation, character reference sheets, and retries.',
                'sort_order' => 10,
            ],
            [
                'code' => 'fal-ai/flux-pro/kontext',
                'display_name' => 'FLUX Kontext Pro',
                'capability' => 'cover_generation',
                'capabilities' => ['cover_generation', 'premium_retry', 'image_editing'],
                'cost' => '0.0800',
                'notes' => 'Premium cover generation, difficult retries, and high-priority scenes.',
                'sort_order' => 20,
            ],
        ] as $model) {
            DB::table('ai_models')->updateOrInsert(
                ['ai_provider_id' => $providerId, 'code' => $model['code']],
                [
                    'display_name' => $model['display_name'],
                    'capability' => $model['capability'],
                    'generation_capabilities_json' => json_encode($model['capabilities']),
                    'estimated_cost_per_output' => $model['cost'],
                    'estimated_cost_type' => 'estimated',
                    'estimated_cost_amount' => $model['cost'],
                    'estimated_cost_currency' => 'USD',
                    'cost_unit' => 'per_image',
                    'notes' => $model['notes'],
                    'sort_order' => $model['sort_order'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
};

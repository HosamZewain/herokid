<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'production_studio.ai_generate',
        'production_studio.ai_review',
        'production_studio.ai_approve',
        'production_studio.ai_retry',
        'production_studio.ai_view_costs',
        'production_studio.ai_manage_providers',
    ];

    public function up(): void
    {
        $this->addSceneGenerationJobColumns();
        $this->addProductionProjectAssetColumns();

        $this->registerPermissions();
        $this->registerFalProvider();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        if (Schema::hasTable('ai_models')) {
            DB::table('ai_models')->whereIn('code', [
                'fal-ai/flux-kontext/dev',
                'fal-ai/flux-pro/kontext',
            ])->delete();
        }

        if (Schema::hasTable('ai_providers')) {
            DB::table('ai_providers')->where('driver', 'fal')->delete();
        }

        Schema::table('production_project_assets', function (Blueprint $table) {
            $table->dropIndex('ps_assets_project_type_status_idx');
            $table->dropIndex('ps_assets_scene_type_final_idx');
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('scene_generation_job_id');
            $table->dropConstrainedForeignId('production_scene_id');
            $table->dropColumn([
                'version_number',
                'status',
                'is_primary',
                'is_final',
                'review_notes',
                'rejection_reason',
                'reviewed_at',
                'archived_at',
            ]);
        });

        Schema::table('scene_generation_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'job_type',
                'external_request_id',
                'external_status_url',
                'external_response_url',
                'provider_request_json',
                'provider_response_json',
                'cost_source',
                'submitted_at',
                'completed_at',
                'failed_at',
            ]);
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

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $this->permissionKeys)
            ->pluck('id');

        $adminIds = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id');

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

    private function registerFalProvider(): void
    {
        if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
            return;
        }

        $now = now();

        DB::table('ai_providers')->updateOrInsert(
            ['driver' => 'fal'],
            [
                'name' => 'fal.ai',
                'is_active' => false,
                'configuration_reference' => 'FAL_KEY',
                'supports_text_to_image' => true,
                'supports_image_to_image' => true,
                'supports_editing' => true,
                'supports_upscaling' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $providerId = DB::table('ai_providers')->where('driver', 'fal')->value('id');

        foreach ([
            [
                'code' => 'fal-ai/flux-kontext/dev',
                'display_name' => 'FLUX Kontext Dev',
                'estimated_cost_per_output' => '0.0300',
                'generation_capabilities_json' => ['character_scene', 'character_sheet'],
            ],
            [
                'code' => 'fal-ai/flux-pro/kontext',
                'display_name' => 'FLUX Kontext Pro',
                'estimated_cost_per_output' => '0.0800',
                'generation_capabilities_json' => ['character_scene', 'character_sheet', 'cover_generation'],
            ],
        ] as $model) {
            DB::table('ai_models')->updateOrInsert(
                ['ai_provider_id' => $providerId, 'code' => $model['code']],
                [
                    'display_name' => $model['display_name'],
                    'generation_capabilities_json' => json_encode($model['generation_capabilities_json']),
                    'estimated_cost_per_output' => $model['estimated_cost_per_output'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function addSceneGenerationJobColumns(): void
    {
        Schema::table('scene_generation_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('scene_generation_jobs', 'job_type')) {
                $table->string('job_type')->default('scene_image')->after('id')->index();
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'external_request_id')) {
                $table->string('external_request_id')->nullable()->after('ai_model_id')->index();
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'external_status_url')) {
                $table->string('external_status_url')->nullable()->after('external_request_id');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'external_response_url')) {
                $table->string('external_response_url')->nullable()->after('external_status_url');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'provider_request_json')) {
                $table->json('provider_request_json')->nullable()->after('negative_prompt_snapshot');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'provider_response_json')) {
                $table->json('provider_response_json')->nullable()->after('output_metadata_json');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'cost_source')) {
                $table->string('cost_source')->nullable()->after('actual_cost');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('initiated_by_user_id');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('scene_generation_jobs', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            }
        });
    }

    private function addProductionProjectAssetColumns(): void
    {
        Schema::table('production_project_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('production_project_assets', 'production_scene_id')) {
                $table->foreignId('production_scene_id')->nullable()->after('production_project_id')->constrained('production_scenes')->nullOnDelete();
            }

            if (! Schema::hasColumn('production_project_assets', 'scene_generation_job_id')) {
                $table->foreignId('scene_generation_job_id')->nullable()->after('production_scene_id')->constrained('scene_generation_jobs')->nullOnDelete();
            }

            if (! Schema::hasColumn('production_project_assets', 'version_number')) {
                $table->unsignedInteger('version_number')->default(1)->after('asset_type');
            }

            if (! Schema::hasColumn('production_project_assets', 'status')) {
                $table->string('status')->default('under_review')->after('label')->index();
            }

            if (! Schema::hasColumn('production_project_assets', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('status');
            }

            if (! Schema::hasColumn('production_project_assets', 'is_final')) {
                $table->boolean('is_final')->default(false)->after('is_primary');
            }

            if (! Schema::hasColumn('production_project_assets', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('metadata_json');
            }

            if (! Schema::hasColumn('production_project_assets', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('review_notes');
            }

            if (! Schema::hasColumn('production_project_assets', 'reviewed_by_user_id')) {
                $table->foreignId('reviewed_by_user_id')->nullable()->after('uploaded_by_user_id')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('production_project_assets', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            }

            if (! Schema::hasColumn('production_project_assets', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('reviewed_at');
            }
        });

        if (! $this->indexExists('production_project_assets', 'ps_assets_project_type_status_idx')) {
            Schema::table('production_project_assets', function (Blueprint $table) {
                $table->index(['production_project_id', 'asset_type', 'status'], 'ps_assets_project_type_status_idx');
            });
        }

        if (! $this->indexExists('production_project_assets', 'ps_assets_scene_type_final_idx')) {
            Schema::table('production_project_assets', function (Blueprint $table) {
                $table->index(['production_scene_id', 'asset_type', 'is_final'], 'ps_assets_scene_type_final_idx');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(
                fn (array $existingIndex) => ($existingIndex['name'] ?? null) === $index
            );
        } catch (Throwable) {
            return false;
        }
    }
};

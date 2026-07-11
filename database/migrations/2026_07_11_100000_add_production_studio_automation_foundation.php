<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'production_studio.automation_manage',
        'production_studio.automation_view_costs',
    ];

    public function up(): void
    {
        Schema::create('production_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->foreignId('active_project_id')->nullable()->constrained('production_projects')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('orchestration_generation')->default(1);
            $table->string('status')->default('queued')->index();
            $table->string('current_stage')->default('preflight')->index();
            $table->string('current_step_key')->nullable()->index();
            $table->decimal('base_estimated_cost', 12, 4)->default(0);
            $table->decimal('retry_exposure_estimate', 12, 4)->default(0);
            $table->decimal('hard_budget', 12, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->json('options_snapshot_json')->nullable();
            $table->json('pricing_snapshot_json')->nullable();
            $table->json('blockers_json')->nullable();
            $table->string('pause_reason')->nullable();
            $table->string('safe_failure_code')->nullable();
            $table->text('safe_failure_summary')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->unsignedInteger('provider_wait_seconds')->default(0);
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('files_ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_transition_at')->nullable();
            $table->timestamps();

            $table->unique('active_project_id', 'production_automation_runs_one_active_project_unique');
            $table->index(['production_project_id', 'status'], 'production_automation_runs_project_status_idx');
        });

        Schema::create('production_automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('production_automation_runs')->cascadeOnDelete();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->foreignId('production_scene_id')->nullable()->constrained('production_scenes')->nullOnDelete();
            $table->string('step_key');
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->string('stage')->index();
            $table->string('status')->default('pending')->index();
            $table->decimal('weight', 8, 4)->default(0);
            $table->unsignedInteger('attempt_limit')->default(1);
            $table->unsignedInteger('attempt_number')->default(0);
            $table->unsignedInteger('run_version')->default(1);
            $table->string('input_fingerprint')->nullable()->index();
            $table->string('output_fingerprint')->nullable()->index();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('validation_policy_version')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_type')->nullable();
            $table->string('safe_failure_code')->nullable();
            $table->text('safe_failure_summary')->nullable();
            $table->json('metadata_json')->nullable();
            $table->json('validation_summary_json')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'step_key'], 'production_automation_steps_run_key_unique');
            $table->index(['automation_run_id', 'status'], 'production_automation_steps_run_status_idx');
        });

        Schema::create('production_automation_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->foreignId('automation_run_id')->constrained('production_automation_runs')->cascadeOnDelete();
            $table->foreignId('automation_step_id')->constrained('production_automation_steps')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->unsignedInteger('run_version')->default(1);
            $table->unsignedInteger('orchestration_generation')->default(1);
            $table->string('status')->default('queued')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('input_fingerprint')->nullable()->index();
            $table->string('output_fingerprint')->nullable()->index();
            $table->string('validation_policy_version')->nullable();
            $table->json('input_summary_json')->nullable();
            $table->json('validation_result_json')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_type')->nullable();
            $table->string('safe_failure_code')->nullable();
            $table->text('safe_failure_summary')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_step_id', 'attempt_number'], 'production_automation_attempts_step_number_unique');
            $table->index(['automation_run_id', 'status'], 'production_automation_attempts_run_status_idx');
        });

        Schema::create('production_automation_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('production_automation_runs')->cascadeOnDelete();
            $table->foreignId('automation_step_id')->nullable()->constrained('production_automation_steps')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('production_automation_attempts')->nullOnDelete();
            $table->unsignedBigInteger('released_from_cost_entry_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('status')->index();
            $table->decimal('estimated_amount', 12, 4)->default(0);
            $table->decimal('actual_amount', 12, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->json('pricing_snapshot')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'status'], 'production_automation_cost_entries_run_status_idx');
        });

        Schema::table('production_automation_cost_entries', function (Blueprint $table) {
            $table->foreign('released_from_cost_entry_id', 'pa_cost_released_from_fk')
                ->references('id')
                ->on('production_automation_cost_entries')
                ->nullOnDelete();
        });

        $this->addAutomationColumns();
        $this->registerPermissions();
    }

    public function down(): void
    {
        $this->dropAutomationColumns();

        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('production_automation_cost_entries');
        Schema::dropIfExists('production_automation_attempts');
        Schema::dropIfExists('production_automation_steps');
        Schema::dropIfExists('production_automation_runs');
    }

    private function addAutomationColumns(): void
    {
        Schema::table('scene_generation_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('scene_generation_jobs', 'production_automation_run_id')) {
                $table->foreignId('production_automation_run_id')->nullable()->after('production_project_id')->constrained('production_automation_runs')->nullOnDelete();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'production_automation_step_id')) {
                $table->foreignId('production_automation_step_id')->nullable()->after('production_automation_run_id')->constrained('production_automation_steps')->nullOnDelete();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'production_automation_attempt_id')) {
                $table->foreignId('production_automation_attempt_id')->nullable()->after('production_automation_step_id')->constrained('production_automation_attempts')->nullOnDelete();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'provider_request_id')) {
                $table->string('provider_request_id')->nullable()->after('external_request_id')->index();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'input_fingerprint')) {
                $table->string('input_fingerprint')->nullable()->after('input_assets_json')->index();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'output_fingerprint')) {
                $table->string('output_fingerprint')->nullable()->after('output_asset_path')->index();
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'run_version')) {
                $table->unsignedInteger('run_version')->nullable()->after('cost_source');
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'orchestration_generation')) {
                $table->unsignedInteger('orchestration_generation')->nullable()->after('run_version');
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('failed_at');
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'validation_policy_version')) {
                $table->string('validation_policy_version')->nullable()->after('heartbeat_at');
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'safe_failure_code')) {
                $table->string('safe_failure_code')->nullable()->after('validation_policy_version');
            }
            if (! Schema::hasColumn('scene_generation_jobs', 'safe_failure_summary')) {
                $table->text('safe_failure_summary')->nullable()->after('safe_failure_code');
            }
        });

        Schema::table('production_project_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('production_project_assets', 'production_automation_run_id')) {
                $table->foreignId('production_automation_run_id')->nullable()->after('production_project_id');
            }
            if (! Schema::hasColumn('production_project_assets', 'production_automation_step_id')) {
                $table->foreignId('production_automation_step_id')->nullable()->after('production_automation_run_id');
            }
            if (! Schema::hasColumn('production_project_assets', 'production_automation_attempt_id')) {
                $table->foreignId('production_automation_attempt_id')->nullable()->after('production_automation_step_id');
            }
            if (! Schema::hasColumn('production_project_assets', 'input_fingerprint')) {
                $table->string('input_fingerprint')->nullable()->after('metadata_json')->index();
            }
            if (! Schema::hasColumn('production_project_assets', 'output_fingerprint')) {
                $table->string('output_fingerprint')->nullable()->after('input_fingerprint')->index();
            }
            if (! Schema::hasColumn('production_project_assets', 'validation_policy_version')) {
                $table->string('validation_policy_version')->nullable()->after('output_fingerprint');
            }
        });

        Schema::table('production_print_layouts', function (Blueprint $table) {
            if (! Schema::hasColumn('production_print_layouts', 'production_automation_run_id')) {
                $table->foreignId('production_automation_run_id')->nullable()->after('production_project_id');
            }
            if (! Schema::hasColumn('production_print_layouts', 'production_automation_step_id')) {
                $table->foreignId('production_automation_step_id')->nullable()->after('production_automation_run_id');
            }
            if (! Schema::hasColumn('production_print_layouts', 'production_automation_attempt_id')) {
                $table->foreignId('production_automation_attempt_id')->nullable()->after('production_automation_step_id');
            }
            if (! Schema::hasColumn('production_print_layouts', 'input_fingerprint')) {
                $table->string('input_fingerprint')->nullable()->after('settings_json')->index();
            }
            if (! Schema::hasColumn('production_print_layouts', 'output_fingerprint')) {
                $table->string('output_fingerprint')->nullable()->after('input_fingerprint')->index();
            }
            if (! Schema::hasColumn('production_print_layouts', 'validation_policy_version')) {
                $table->string('validation_policy_version')->nullable()->after('output_fingerprint');
            }
        });

        Schema::table('production_project_assets', function (Blueprint $table) {
            $table->foreign('production_automation_run_id', 'ppa_auto_run_fk')->references('id')->on('production_automation_runs')->nullOnDelete();
            $table->foreign('production_automation_step_id', 'ppa_auto_step_fk')->references('id')->on('production_automation_steps')->nullOnDelete();
            $table->foreign('production_automation_attempt_id', 'ppa_auto_attempt_fk')->references('id')->on('production_automation_attempts')->nullOnDelete();
        });

        Schema::table('production_print_layouts', function (Blueprint $table) {
            $table->foreign('production_automation_run_id', 'ppl_auto_run_fk')->references('id')->on('production_automation_runs')->nullOnDelete();
            $table->foreign('production_automation_step_id', 'ppl_auto_step_fk')->references('id')->on('production_automation_steps')->nullOnDelete();
            $table->foreign('production_automation_attempt_id', 'ppl_auto_attempt_fk')->references('id')->on('production_automation_attempts')->nullOnDelete();
        });
    }

    private function dropAutomationColumns(): void
    {
        $foreignColumns = [
            'production_automation_run_id',
            'production_automation_step_id',
            'production_automation_attempt_id',
        ];
        $foreignNames = [
            'production_project_assets.production_automation_run_id' => 'ppa_auto_run_fk',
            'production_project_assets.production_automation_step_id' => 'ppa_auto_step_fk',
            'production_project_assets.production_automation_attempt_id' => 'ppa_auto_attempt_fk',
            'production_print_layouts.production_automation_run_id' => 'ppl_auto_run_fk',
            'production_print_layouts.production_automation_step_id' => 'ppl_auto_step_fk',
            'production_print_layouts.production_automation_attempt_id' => 'ppl_auto_attempt_fk',
        ];

        foreach ([
            'scene_generation_jobs' => [
                'production_automation_run_id',
                'production_automation_step_id',
                'production_automation_attempt_id',
                'provider_request_id',
                'input_fingerprint',
                'output_fingerprint',
                'run_version',
                'orchestration_generation',
                'heartbeat_at',
                'validation_policy_version',
                'safe_failure_code',
                'safe_failure_summary',
            ],
            'production_project_assets' => [
                'production_automation_run_id',
                'production_automation_step_id',
                'production_automation_attempt_id',
                'input_fingerprint',
                'output_fingerprint',
                'validation_policy_version',
            ],
            'production_print_layouts' => [
                'production_automation_run_id',
                'production_automation_step_id',
                'production_automation_attempt_id',
                'input_fingerprint',
                'output_fingerprint',
                'validation_policy_version',
            ],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns, $foreignColumns, $foreignNames): void {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    if (in_array($column, $foreignColumns, true)) {
                        $foreignName = $foreignNames[$tableName.'.'.$column] ?? null;
                        if ($foreignName) {
                            $table->dropForeign($foreignName);
                            $table->dropColumn($column);
                        } else {
                            $table->dropConstrainedForeignId($column);
                        }
                    } else {
                        $table->dropColumn($column);
                    }
                }
            });
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
};

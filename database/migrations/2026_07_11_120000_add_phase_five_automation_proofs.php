<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'production_studio.final_proof_review',
    ];

    public function up(): void
    {
        Schema::create('production_automation_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained('production_automation_runs')->cascadeOnDelete();
            $table->foreignId('current_run_id')->nullable()->constrained('production_automation_runs')->nullOnDelete();
            $table->foreignId('production_print_layout_id')->constrained('production_print_layouts')->cascadeOnDelete();
            $table->unsignedInteger('proof_version');
            $table->string('status')->default('draft')->index();
            $table->string('input_fingerprint')->nullable()->index();
            $table->string('reader_pdf_checksum')->nullable();
            $table->string('imposed_pdf_checksum')->nullable();
            $table->string('manifest_checksum')->nullable();
            $table->string('proof_checklist_checksum')->nullable();
            $table->json('checklist_snapshot')->nullable();
            $table->json('print_test_metadata')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('failure_category')->nullable();
            $table->string('affected_component')->nullable();
            $table->unsignedInteger('affected_scene_number')->nullable();
            $table->string('report_status')->default('pending')->index();
            $table->string('report_path')->nullable();
            $table->string('report_checksum')->nullable();
            $table->timestamp('report_generated_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();

            $table->unique('current_run_id', 'production_automation_proofs_one_current_run_unique');
            $table->unique(['automation_run_id', 'proof_version'], 'production_automation_proofs_run_version_unique');
            $table->index(['automation_run_id', 'status'], 'production_automation_proofs_run_status_idx');
        });

        $this->registerPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('production_automation_proofs');
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

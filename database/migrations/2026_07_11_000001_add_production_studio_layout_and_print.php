<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'production_studio.layout_manage',
        'production_studio.layout_download',
    ];

    public function up(): void
    {
        Schema::create('production_print_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_project_id')->constrained('production_projects')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft')->index();
            $table->json('settings_json')->nullable();
            $table->json('manifest_json')->nullable();
            $table->string('reader_pdf_path')->nullable();
            $table->string('print_pdf_path')->nullable();
            $table->string('manifest_path')->nullable();
            $table->string('proof_checklist_path')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['production_project_id', 'version_number'], 'production_print_layouts_project_version_unique');
            $table->index(['production_project_id', 'status'], 'production_print_layouts_project_status_idx');
        });

        $now = now();
        foreach ([
            'production_layout_website' => 'hero-kid.com',
            'production_back_cover_text' => 'قصة صُممت خصيصًا ليكون طفلك بطلها.',
            'production_cover_subtitle_template' => 'بطولة {{child_name}}',
        ] as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        Cache::forget('site_settings');

        $this->registerPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user') && Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('production_print_layouts');
        DB::table('settings')->whereIn('key', [
            'production_layout_website',
            'production_back_cover_text',
            'production_cover_subtitle_template',
        ])->delete();
        Cache::forget('site_settings');
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

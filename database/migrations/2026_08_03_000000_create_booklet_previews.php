<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'booklet_previews.view',
        'booklet_previews.create',
        'booklet_previews.update',
        'booklet_previews.publish',
        'booklet_previews.revoke',
        'booklet_previews.delete',
        'booklet_previews.download_source',
    ];

    public function up(): void
    {
        Schema::create('booklet_previews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type', 30)->index();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('story_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('reading_direction', 3)->default('rtl');
            $table->string('status', 30)->default('active')->index();
            $table->char('public_token_hash', 64)->unique();
            $table->text('public_token_encrypted');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->boolean('show_on_story')->default(false)->index();
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['source_type', 'status', 'created_at'], 'booklet_previews_source_status_idx');
            $table->index(['story_id', 'show_on_story', 'status'], 'booklet_previews_story_public_idx');
        });

        Schema::create('booklet_preview_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booklet_preview_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('disk', 50)->default('local');
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->unsignedInteger('page_count');
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['booklet_preview_id', 'version_number'], 'booklet_preview_version_unique');
        });

        Schema::table('booklet_previews', function (Blueprint $table): void {
            $table->foreign('current_version_id', 'booklet_previews_current_version_fk')
                ->references('id')->on('booklet_preview_versions')->nullOnDelete();
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

        Schema::table('booklet_previews', function (Blueprint $table): void {
            $table->dropForeign('booklet_previews_current_version_fk');
        });
        Schema::dropIfExists('booklet_preview_versions');
        Schema::dropIfExists('booklet_previews');
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

<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_stories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 150)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable()->index();
            $table->string('language', 2)->default('ar')->index();
            $table->string('reading_direction', 3)->default('rtl');
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('sort_order')->default(0);
            $table->string('disk');
            $table->string('pdf_path');
            $table->string('cover_path')->nullable();
            $table->string('cover_mime', 30)->nullable();
            $table->unsignedSmallInteger('page_count');
            $table->unsignedBigInteger('file_size');
            $table->string('checksum', 64);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'sort_order', 'id']);
        });
        foreach (['story_library.view', 'story_library.manage'] as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $definition['group_key'], 'name_ar' => $definition['name_ar'], 'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'], 'description_en' => $definition['description_en'],
                'sort_order' => $definition['sort_order'], 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            $managers = DB::table('permission_user')->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
                ->join('users', 'users.id', '=', 'permission_user.user_id')->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
                ->where('users.role', 'admin')->where('users.is_active', true)->pluck('users.id');
            foreach ($managers as $id) {
                DB::table('permission_user')->insertOrIgnore(['user_id' => $id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_stories');
        DB::table('permissions')->whereIn('key', ['story_library.view', 'story_library.manage'])->delete();
    }
};

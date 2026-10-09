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
        Schema::table('whatsapp_conversations', fn (Blueprint $table) => $table->unsignedBigInteger('inbound_version')->default(0));
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('employee_name')->nullable();
        });
        Schema::create('whatsapp_conversation_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('inbound_version')->default(0);
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id'], 'whatsapp_employee_read_unique');
        });
        Schema::create('whatsapp_conversation_replies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('employee_name');
            $table->string('account_key', 64);
            $table->char('phone_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('state', 20)->default('sending');
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('error_status')->nullable();
            $table->string('attachment_disk')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 40)->nullable();
            $table->timestamp('attachment_expires_at')->nullable()->index();
            $table->timestamps();
        });
        DB::table('whatsapp_conversations')->whereExists(fn ($q) => $q->selectRaw('1')->from('whatsapp_conversation_messages')
            ->whereColumn('conversation_id', 'whatsapp_conversations.id')->where('direction', 'inbound'))->update(['inbound_version' => 1]);
        foreach (['orders.conversations.reply', 'orders.conversations.view-all'] as $key) {
            $d = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $d['group_key'], 'name_ar' => $d['name_ar'], 'name_en' => $d['name_en'],
                'description_ar' => $d['description_ar'], 'description_en' => $d['description_en'],
                'sort_order' => $d['sort_order'], 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            $managers = DB::table('permission_user')->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
                ->join('users', 'users.id', '=', 'permission_user.user_id')->where('users.role', 'admin')->where('users.is_active', true)
                ->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('users.id');
            foreach ($managers as $id) {
                DB::table('permission_user')->insertOrIgnore(['permission_id' => $permissionId, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_replies');
        Schema::dropIfExists('whatsapp_conversation_reads');
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('employee_name');
        });
        Schema::table('whatsapp_conversations', fn (Blueprint $table) => $table->dropColumn('inbound_version'));
        DB::table('permissions')->whereIn('key', ['orders.conversations.reply', 'orders.conversations.view-all'])->delete();
    }
};

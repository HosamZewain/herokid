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
        Schema::create('robodesk_conversation_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->text('email')->nullable();
            $table->text('password')->nullable();
            $table->unsignedTinyInteger('conversation_limit')->default(20);
            $table->timestamps();
        });
        Schema::create('whatsapp_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key', 64);
            $table->char('phone_hash', 64);
            $table->text('phone');
            $table->text('sync_cursor')->nullable();
            $table->string('sync_state', 20)->default('idle');
            $table->timestamp('sync_requested_at')->nullable();
            $table->timestamp('sync_started_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['account_key', 'phone_hash'], 'whatsapp_contact_unique');
        });
        Schema::create('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->char('remote_hash', 64);
            $table->text('remote_id');
            $table->string('direction', 10);
            $table->string('kind', 20)->default('text');
            $table->longText('body')->nullable();
            $table->longText('attachments')->nullable();
            $table->text('sender_name')->nullable();
            $table->string('sender_type', 20)->nullable();
            $table->char('reply_to_hash', 64)->nullable();
            $table->string('delivery_status', 20)->nullable();
            $table->timestamp('sent_at', 3);
            $table->timestamp('source_updated_at')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->unique(['conversation_id', 'remote_hash'], 'whatsapp_message_unique');
            $table->index(['conversation_id', 'sent_at', 'id'], 'whatsapp_message_timeline');
        });
        $key = 'orders.conversations.view';
        $definition = AdminPermissionRegistry::metadata($key);
        DB::table('permissions')->updateOrInsert(['key' => $key], [
            'group_key' => $definition['group_key'], 'name_ar' => $definition['name_ar'], 'name_en' => $definition['name_en'],
            'description_ar' => $definition['description_ar'], 'description_en' => $definition['description_en'],
            'sort_order' => $definition['sort_order'], 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('permissions')->where('key', $key)->value('id');
        $managers = DB::table('users')->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('users.role', 'admin')->where('users.is_active', true)
            ->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('users.id');
        foreach ($managers as $userId) {
            DB::table('permission_user')->insertOrIgnore(['permission_id' => $id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('robodesk_conversation_settings');
        DB::table('permissions')->where('key', 'orders.conversations.view')->delete();
    }
};

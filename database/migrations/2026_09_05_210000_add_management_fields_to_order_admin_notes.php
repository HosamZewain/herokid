<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_admin_notes', function (Blueprint $table): void {
            $table->foreignId('attachment_id')->nullable()->after('body')->constrained('order_attachments')->nullOnDelete();
            $table->foreignId('last_edited_by_user_id')->nullable()->after('attachment_id')->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by_user_id')->nullable()->after('last_edited_by_user_id')->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('order_admin_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('attachment_id');
            $table->dropConstrainedForeignId('last_edited_by_user_id');
            $table->dropConstrainedForeignId('deleted_by_user_id');
            $table->dropSoftDeletes();
        });
    }
};

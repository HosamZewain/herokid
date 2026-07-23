<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_photo_uploads', function (Blueprint $table): void {
            $table->string('batch_hash', 64)->nullable()->after('session_hash');
            $table->index(['session_hash', 'batch_hash', 'status'], 'temp_upload_session_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('temporary_photo_uploads', function (Blueprint $table): void {
            $table->dropIndex('temp_upload_session_batch_status_idx');
            $table->dropColumn('batch_hash');
        });
    }
};

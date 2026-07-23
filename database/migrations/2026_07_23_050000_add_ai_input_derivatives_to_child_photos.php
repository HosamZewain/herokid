<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_photo_uploads', function (Blueprint $table): void {
            $table->string('prepared_disk', 50)->nullable()->after('path');
            $table->string('prepared_path')->nullable()->after('prepared_disk');
            $table->string('prepared_mime_type', 100)->nullable()->after('prepared_path');
            $table->unsignedBigInteger('prepared_file_size')->nullable()->after('prepared_mime_type');
            $table->unsignedInteger('prepared_width')->nullable()->after('prepared_file_size');
            $table->unsignedInteger('prepared_height')->nullable()->after('prepared_width');
            $table->string('prepared_checksum', 64)->nullable()->after('prepared_height');
        });

        Schema::table('child_identity_photos', function (Blueprint $table): void {
            $table->string('ai_input_disk', 50)->nullable()->after('path');
            $table->string('ai_input_path')->nullable()->after('ai_input_disk');
            $table->string('ai_input_mime_type', 100)->nullable()->after('ai_input_path');
            $table->string('ai_input_checksum', 64)->nullable()->after('ai_input_mime_type');
        });

        Schema::table('child_identity_attempt_photos', function (Blueprint $table): void {
            $table->string('mime_type', 100)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('child_identity_attempt_photos', function (Blueprint $table): void {
            $table->dropColumn('mime_type');
        });

        Schema::table('child_identity_photos', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_input_disk',
                'ai_input_path',
                'ai_input_mime_type',
                'ai_input_checksum',
            ]);
        });

        Schema::table('temporary_photo_uploads', function (Blueprint $table): void {
            $table->dropColumn([
                'prepared_disk',
                'prepared_path',
                'prepared_mime_type',
                'prepared_file_size',
                'prepared_width',
                'prepared_height',
                'prepared_checksum',
            ]);
        });
    }
};

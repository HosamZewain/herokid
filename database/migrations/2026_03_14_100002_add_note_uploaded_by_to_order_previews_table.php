<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_previews', function (Blueprint $table) {
            $table->text('note')->nullable()->after('file_path');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('order_previews', function (Blueprint $table) {
            $table->dropColumn(['note', 'uploaded_by']);
        });
    }
};

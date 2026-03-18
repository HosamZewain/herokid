<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');       // الاسم الأصلي للملف
            $table->string('path');                // المسار داخل storage/app/private
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0); // bytes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_attachments');
    }
};

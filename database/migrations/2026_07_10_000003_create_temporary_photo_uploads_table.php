<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_photo_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('session_hash', 96)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attached_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('attached_cart_key')->nullable()->index();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable()->index();
            $table->string('status', 24)->default('uploaded')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['session_hash', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_photo_uploads');
    }
};

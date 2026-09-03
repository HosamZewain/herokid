<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_product_preview_galleries', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->unique();
            $table->string('status', 20)->default('active')->index();
            $table->char('public_token_hash', 64)->unique();
            $table->text('public_token_encrypted');
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('order_previews', function (Blueprint $table): void {
            $table->foreignId('product_gallery_id')->nullable()->after('order_id')
                ->constrained('order_product_preview_galleries')->cascadeOnDelete();
            $table->string('disk', 50)->default('local')->after('file_path');
            $table->string('original_name')->nullable()->after('disk');
            $table->string('mime_type', 100)->nullable()->after('original_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            $table->char('checksum', 64)->nullable()->after('file_size');
            $table->index(['product_gallery_id', 'created_at'], 'order_previews_product_gallery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_previews', function (Blueprint $table): void {
            $table->dropIndex('order_previews_product_gallery_idx');
            $table->dropConstrainedForeignId('product_gallery_id');
            $table->dropColumn(['disk', 'original_name', 'mime_type', 'file_size', 'checksum']);
        });

        Schema::dropIfExists('order_product_preview_galleries');
    }
};

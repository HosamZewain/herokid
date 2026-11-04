<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_book_cover_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_preview_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('inputs');
            $table->longText('configuration_before')->nullable();
            $table->char('fingerprint', 64);
            $table->timestamps();
            $table->index(['order_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_book_cover_approvals');
    }
};

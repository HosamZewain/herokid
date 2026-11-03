<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipping_merge_requests', function (Blueprint $table) {
            $table->id();
            $table->string('source_checkout_key')->index();
            $table->string('target_checkout_key')->unique();
            $table->foreignId('source_order_id')->constrained('orders');
            $table->foreignId('target_order_id')->constrained('orders');
            $table->string('status')->default('pending')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipping_merge_requests');
    }
};

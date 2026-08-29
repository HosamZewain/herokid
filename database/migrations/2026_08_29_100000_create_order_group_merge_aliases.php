<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_group_merge_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('source_checkout_group_key')->unique();
            $table->string('target_checkout_group_key')->index();
            $table->string('source_short_reference', 32)->nullable()->unique();
            $table->string('target_short_reference', 32)->nullable()->index();
            $table->foreignId('source_representative_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('target_representative_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('merged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('removed_delivery_fee_cents')->default(0);
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('merged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_group_merge_aliases');
    }
};

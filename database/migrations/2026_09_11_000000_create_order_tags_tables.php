<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 40);
            $table->string('normalized_name', 40)->unique();
            $table->timestamps();
        });

        Schema::create('order_checkout_reference_tag', function (Blueprint $table): void {
            $table->foreignId('order_checkout_reference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['order_checkout_reference_id', 'order_tag_id'], 'order_checkout_reference_tag_primary');
            $table->index(['order_tag_id', 'order_checkout_reference_id'], 'order_checkout_reference_tag_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_checkout_reference_tag');
        Schema::dropIfExists('order_tags');
    }
};

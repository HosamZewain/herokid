<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzle_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('order_item_id')->unique();
            $table->unsignedBigInteger('event_id');
            $table->string('checkout_key')->index();
            $table->string('unit_key');
            $table->string('status')->index();
            $table->longText('context')->nullable();
            $table->string('error_code')->nullable();
            $table->string('provider_request_id')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('billing_status')->default('not_billable');
            $table->json('usage')->nullable();
            $table->string('disk')->nullable();
            $table->string('output_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->unsignedBigInteger('preview_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
        });
        Schema::create('puzzle_automation_cursors', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_automation_cursors');
        Schema::dropIfExists('puzzle_generations');
    }
};

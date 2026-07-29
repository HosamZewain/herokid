<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_name', 50)->default('Purchase')->index();
            $table->string('checkout_group_key')->unique();
            $table->foreignId('representative_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('event_time');
            $table->string('event_source_url', 2048);
            $table->longText('user_data_encrypted');
            $table->json('custom_data_json');
            $table->string('provider_request_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('safe_error_message', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_conversion_events');
    }
};

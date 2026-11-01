<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->unique()->constrained('order_process_runs');
            $table->string('checkout_key')->index();
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
        Schema::create('order_payment_review_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('order_payment_reviews');
            $table->foreignId('message_id')->unique()->constrained('whatsapp_conversation_messages');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_review_signals');
        Schema::dropIfExists('order_payment_reviews');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_process_message_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->constrained('order_process_steps')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 24);
            $table->longText('receipt')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['step_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_process_message_attempts');
    }
};

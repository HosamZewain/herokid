<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_automations', function (Blueprint $table) {
            $table->unsignedBigInteger('process_after_event_id')->nullable();
            $table->foreignId('process_activated_by')->nullable()->constrained('users');
        });
        Schema::create('order_process_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 100)->unique();
            $table->string('type', 32);
            $table->foreignId('order_id')->constrained('orders');
            $table->string('checkout_key')->index();
            $table->json('data');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
        Schema::create('order_process_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('order_automations');
            $table->foreignId('revision_id')->constrained('order_automation_revisions');
            $table->foreignId('event_id')->constrained('order_process_events');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('checkout_key')->index();
            $table->string('account_key', 100);
            $table->string('phone_hash', 64)->index();
            $table->string('enrollment_key', 64)->unique();
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('status', 32)->default('queued')->index();
            $table->string('node_id', 40);
            $table->unsignedInteger('version')->default(1);
            $table->longText('context');
            $table->string('error_code', 80)->nullable();
            $table->timestamp('next_at')->nullable()->index();
            $table->timestamp('wait_until')->nullable();
            $table->timestamps();
        });
        Schema::create('order_process_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('order_process_runs');
            $table->string('node_id', 40);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('effect_key', 64)->nullable()->unique();
            $table->longText('receipt')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'node_id']);
        });
        Schema::create('order_process_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('order_process_runs');
            $table->foreignId('step_id')->unique()->constrained('order_process_steps');
            $table->foreignId('message_id')->unique()->constrained('whatsapp_conversation_messages');
            $table->string('decision', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_process_responses');
        Schema::dropIfExists('order_process_steps');
        Schema::dropIfExists('order_process_runs');
        Schema::dropIfExists('order_process_events');
        Schema::table('order_automations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('process_activated_by');
            $table->dropColumn('process_after_event_id');
        });
    }
};

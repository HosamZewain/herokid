<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing-order automation is not a fresh customer consent submission.
        Schema::table('child_identity_requests', function (Blueprint $table) {
            $table->timestamp('consent_accepted_at')->nullable()->change();
            $table->string('consent_version', 40)->nullable()->change();
        });
        Schema::create('order_process_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('order_process_runs');
            $table->foreignId('step_id')->constrained('order_process_steps');
            // One automatic paid attempt per order, across workflows/revisions. No blind replay.
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->foreignId('attempt_id')->unique()->constrained('child_identity_generation_attempts');
            $table->string('status', 24)->default('queued');
            $table->longText('context');
            $table->timestamp('lease_until')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_process_identities');
        // Retain nullable consent columns: never fabricate consent during rollback.
    }
};

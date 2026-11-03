<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_identity_regenerations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained();
            $t->string('checkout_key')->index();
            $t->foreignId('attempt_id')->unique()->constrained('child_identity_generation_attempts');
            $t->foreignId('previous_attempt_id')->unique()->constrained('child_identity_generation_attempts');
            $t->foreignId('requested_by')->constrained('users');
            $t->string('status', 24)->default('queued')->index();
            $t->longText('context');
            $t->string('error_code', 100)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Keep attempt history and its paid-effect evidence during code rollback.
    }
};

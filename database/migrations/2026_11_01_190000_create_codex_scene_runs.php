<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codex_scene_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('checkout_key')->index();
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 32)->index();
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->string('previous_status', 64);
            $table->string('fingerprint', 64);
            $table->longText('snapshot');
            $table->json('scenes')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    { /* Preserve production output and audit evidence. */
    }
};

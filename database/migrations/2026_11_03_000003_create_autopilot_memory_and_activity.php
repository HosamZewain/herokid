<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $t) {
            $t->id();
            $t->string('source_key', 160)->nullable()->unique();
            $t->string('scope_type', 24);
            $t->string('scope_key')->default('');
            $t->string('title', 160);
            $t->text('guidance');
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->uuid('source_run_id')->nullable();
            $t->timestamps();
            $t->index(['status', 'scope_type']);
        });
        Schema::create('ai_memory_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('memory_id')->constrained('ai_memories');
            $t->unsignedInteger('revision');
            $t->longText('snapshot');
            $t->text('change_reason');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamp('created_at');
            $t->unique(['memory_id', 'revision']);
        });
        Schema::table('ai_runtime_states', function (Blueprint $t) {
            $t->string('activity_stage', 40)->default('idle');
            $t->unsignedBigInteger('activity_state_id')->nullable();
            $t->uuid('activity_run_id')->nullable();
            $t->uuid('last_run_id')->nullable();
            $t->json('activity_targets')->nullable();
            $t->timestamp('activity_started_at')->nullable();
            $t->timestamp('activity_updated_at')->nullable();
            $t->string('last_outcome', 80)->nullable();
            $t->timestamp('provider_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        // Forward-only: preserve correction history and activity evidence.
    }
};

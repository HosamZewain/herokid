<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_process_simulations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('order_automations');
            $table->foreignId('revision_id')->constrained('order_automation_revisions');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('request_key', 100)->unique();
            $table->string('status', 32)->index();
            $table->string('node_id', 40)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->json('fixture');
            $table->json('context');
            $table->json('trace');
            $table->timestamp('wait_until')->nullable();
            $table->timestamps();
            $table->index(['automation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_process_simulations');
    }
};

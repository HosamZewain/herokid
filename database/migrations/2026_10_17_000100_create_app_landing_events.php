<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_landing_events', function (Blueprint $table): void {
            $table->id();
            $table->char('dedup_key', 64)->unique();
            $table->char('visitor_hash', 64);
            $table->string('event_type', 20);
            $table->date('occurred_on'); // Calendar date in Africa/Cairo, including DST.
            $table->dateTime('occurred_at'); // UTC, server recorded time only.
            $table->index(['occurred_on', 'event_type']);
            $table->index(['occurred_on', 'visitor_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_landing_events');
    }
};

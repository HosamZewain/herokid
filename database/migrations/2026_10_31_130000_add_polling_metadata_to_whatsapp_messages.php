<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->text('provider_metadata')->nullable();
            $table->unsignedBigInteger('confirmation_event_id')->nullable();
        });
    }

    public function down(): void
    {
        // Retain encrypted correlation evidence on a code rollback.
    }
};

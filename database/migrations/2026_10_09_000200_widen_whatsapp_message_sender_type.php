<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->string('sender_type', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        if (DB::table('whatsapp_conversation_messages')->whereRaw($length.'(sender_type) > 20')->exists()) {
            throw new RuntimeException('Cannot shrink sender_type: existing message metadata exceeds 20 characters. No data was changed.');
        }

        Schema::table('whatsapp_conversation_messages', function (Blueprint $table): void {
            $table->string('sender_type', 20)->nullable()->change();
        });
    }
};

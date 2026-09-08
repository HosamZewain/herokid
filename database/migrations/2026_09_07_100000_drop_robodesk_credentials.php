<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each integration now carries its own token, used for both outbound calls
     * and authenticating RoboDesk's callbacks, so there is no separate shared
     * credential left to store.
     */
    public function up(): void
    {
        Schema::dropIfExists('robodesk_credentials');
    }

    public function down(): void
    {
        Schema::create('robodesk_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('credential_type', 50)->unique();
            $table->text('encrypted_value');
            $table->string('last_four', 8)->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collapses the per-action parameter bag into the three fields an
     * integration actually needs: where to POST, what token to send, and the
     * JSON body. Everything else that used to be configurable — endpoint path,
     * HTTP verb, template name, channel, language — is now just text the admin
     * types inside the payload, which is where RoboDesk's contract belongs.
     */
    public function up(): void
    {
        Schema::create('robodesk_integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('integration_key')->unique();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('api_url', 500)->nullable();
            $table->text('encrypted_token')->nullable();
            $table->longText('payload_template')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::dropIfExists('robodesk_action_settings');
    }

    public function down(): void
    {
        Schema::create('robodesk_action_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('action_key')->unique();
            $table->boolean('is_enabled')->default(false)->index();
            $table->json('params')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::dropIfExists('robodesk_integration_settings');
    }
};

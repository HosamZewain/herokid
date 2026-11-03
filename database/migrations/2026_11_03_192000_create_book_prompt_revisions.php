<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_prompt_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('component_id');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('stage', 32);
            $table->string('before_fingerprint', 64);
            $table->string('after_fingerprint', 64);
            $table->longText('previous_template');
            $table->longText('new_template');
            $table->timestamps();
            $table->index(['order_item_id', 'stage']);
        });
    }

    public function down(): void
    {
        // Preserve approval lineage and prompt history during code recovery.
    }
};

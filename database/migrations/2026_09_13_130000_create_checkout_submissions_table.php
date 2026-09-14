<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_submissions', function (Blueprint $table): void {
            $table->string('key_hash', 64)->primary();
            $table->string('session_hash', 64)->index();
            $table->json('order_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_submissions');
    }
};

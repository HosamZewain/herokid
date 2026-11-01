<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_automation_holds', function (Blueprint $table) {
            $table->string('checkout_key')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('fingerprint', 64);
            $table->json('runs');
            $table->json('confirmations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Holds are operational ownership records; never drop them automatically.
    }
};

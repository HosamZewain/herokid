<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('expense_transactions')->restrictOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
    }
};

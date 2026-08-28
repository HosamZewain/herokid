<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_admin_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->index();
            $table->foreignId('representative_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            $table->timestamps();

            $table->index(['checkout_group_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_admin_notes');
    }
};

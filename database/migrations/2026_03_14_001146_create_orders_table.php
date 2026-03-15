<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            
            $table->string('child_name');
            $table->integer('child_age');
            $table->string('child_gender');
            
            $table->string('language')->default('ar');
            $table->string('lesson')->nullable();
            $table->text('interests')->nullable();
            
            $table->text('notes')->nullable();
            $table->text('gift_note')->nullable();
            
            $table->json('delivery_details')->nullable();
            $table->json('uploaded_photos')->nullable();
            
            $table->string('status')->default('new');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

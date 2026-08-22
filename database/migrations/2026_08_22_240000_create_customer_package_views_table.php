<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_package_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pricing_package_id')->constrained()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('viewed_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'viewed_at']);
            $table->index(['pricing_package_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_package_views');
    }
};

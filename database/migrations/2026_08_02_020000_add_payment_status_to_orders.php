<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_status', 32)->default('unpaid')->after('discount_reason')->index();
            $table->unsignedInteger('paid_amount_cents')->default(0)->after('payment_status');
            $table->string('payment_method')->nullable()->after('paid_amount_cents');
            $table->foreignId('payment_updated_by_user_id')->nullable()->after('payment_method')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_updated_at')->nullable()->after('payment_updated_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_updated_by_user_id');
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['payment_status', 'paid_amount_cents', 'payment_method', 'payment_updated_at']);
        });
    }
};

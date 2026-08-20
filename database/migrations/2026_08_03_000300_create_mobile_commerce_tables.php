<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('minimum_subtotal_cents')->default(0);
            $table->unsignedInteger('maximum_discount_cents')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mobile_carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('active')->index();
            $table->string('currency', 3)->default('EGP');
            $table->foreignId('mobile_promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('delivery_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('mobile_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mobile_cart_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 30);
            $table->foreignId('story_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('linked_mobile_cart_item_id')->nullable()->constrained('mobile_cart_items')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('child_profile_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('child_identity_request_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('title');
            $table->string('sku')->nullable();
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('total_price_cents');
            $table->longText('personalization')->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->unique(['mobile_cart_id', 'idempotency_key'], 'mobile_cart_item_idempotency_unique');
        });

        Schema::create('mobile_checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mobile_cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->string('payment_method', 40);
            $table->string('status', 30)->default('processing')->index();
            $table->string('checkout_group_key')->nullable()->index();
            $table->longText('response_payload')->nullable();
            $table->text('safe_error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'mobile_checkout_user_idempotency_unique');
        });

        Schema::create('mobile_payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mobile_checkout_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('method', 40);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EGP');
            $table->string('provider_reference_hash', 64)->nullable()->unique();
            $table->longText('provider_payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mobile_promo_code_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mobile_promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mobile_checkout_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('discount_cents');
            $table->timestamps();
            $table->index(['mobile_promo_code_id', 'user_id'], 'mobile_promo_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_promo_code_redemptions');
        Schema::dropIfExists('mobile_payment_intents');
        Schema::dropIfExists('mobile_checkout_attempts');
        Schema::dropIfExists('mobile_cart_items');
        Schema::dropIfExists('mobile_carts');
        Schema::dropIfExists('mobile_promo_codes');
    }
};

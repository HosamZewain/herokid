<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bosta_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('bosta_delivery_id')->nullable()->unique();
            $table->string('tracking_number')->nullable()->unique();
            $table->string('business_reference')->index();
            $table->string('creation_status')->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('state_code')->nullable()->index();
            $table->string('shipping_status')->nullable();
            $table->unsignedBigInteger('cod_amount_cents')->default(0);
            $table->unsignedBigInteger('provider_reported_cod_cents')->nullable();
            $table->string('package_type')->default('Small');
            $table->boolean('allow_open_package')->default(false);
            $table->string('business_location_id');
            $table->json('provider_response')->nullable();
            $table->timestamp('delivery_promise_date')->nullable();
            $table->unsignedSmallInteger('number_of_attempts')->default(0);
            $table->string('exception_code')->nullable();
            $table->text('exception_reason')->nullable();
            $table->boolean('is_confirmed_delivery')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bosta_shipment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bosta_shipment_id')->constrained()->cascadeOnDelete();
            $table->string('event_key')->unique();
            $table->unsignedSmallInteger('state_code')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('bosta_pickups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('bosta_pickup_id')->nullable()->unique();
            $table->date('scheduled_date');
            $table->string('business_location_id');
            $table->string('contact_name');
            $table->string('contact_phone');
            $table->text('notes')->nullable();
            $table->unsignedInteger('number_of_parcels');
            $table->string('package_type')->default('Normal');
            $table->string('status')->default('requested');
            $table->json('provider_response')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bosta_pickup_shipment', function (Blueprint $table): void {
            $table->foreignId('bosta_pickup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bosta_shipment_id')->constrained()->cascadeOnDelete();
            $table->primary(['bosta_pickup_id', 'bosta_shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bosta_pickup_shipment');
        Schema::dropIfExists('bosta_pickups');
        Schema::dropIfExists('bosta_shipment_events');
        Schema::dropIfExists('bosta_shipments');
    }
};

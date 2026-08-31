<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('checkout_group_key', 191)->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('source', 80)->index();
            $table->string('previous_status', 64)->nullable();
            $table->string('new_status', 64);
            $table->unsignedBigInteger('previous_paid_amount_cents')->default(0);
            $table->unsignedBigInteger('new_paid_amount_cents')->default(0);
            $table->bigInteger('amount_delta_cents')->default(0);
            $table->boolean('affects_collection_stats')->default(false)->index();
            $table->string('payment_method')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['checkout_group_key', 'occurred_at'], 'payment_events_group_occurred_idx');
            $table->index(['event_type', 'occurred_at'], 'payment_events_type_occurred_idx');
        });

        $legacyGroups = DB::table('orders')
            ->selectRaw('checkout_group_key, MIN(id) as order_id, MIN(created_at) as created_at, MAX(payment_updated_at) as payment_updated_at')
            ->whereNotNull('checkout_group_key')
            ->where('checkout_group_key', '!=', '')
            ->groupBy('checkout_group_key')
            ->get();

        foreach ($legacyGroups as $group) {
            // The admin order view treats the first order as the checkout's
            // canonical payment state. Keep the migration aligned with that
            // rule instead of using string MAX() on statuses or methods.
            $paymentState = DB::table('orders')
                ->where('id', (int) $group->order_id)
                ->first(['payment_status', 'paid_amount_cents', 'payment_method']);

            DB::table('order_payment_events')->insert([
                'event_uuid' => (string) Str::uuid(),
                'checkout_group_key' => (string) $group->checkout_group_key,
                'order_id' => (int) $group->order_id,
                'actor_user_id' => null,
                'event_type' => 'legacy_baseline',
                'source' => 'legacy_baseline',
                'previous_status' => null,
                'new_status' => (string) ($paymentState?->payment_status ?: 'unpaid'),
                'previous_paid_amount_cents' => 0,
                'new_paid_amount_cents' => max(0, (int) ($paymentState?->paid_amount_cents ?? 0)),
                'amount_delta_cents' => 0,
                'affects_collection_stats' => false,
                'payment_method' => $paymentState?->payment_method,
                'occurred_at' => $group->payment_updated_at ?: $group->created_at,
                'metadata' => json_encode([
                    'historical_snapshot' => true,
                    'excluded_from_daily_collections' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_events');
    }
};

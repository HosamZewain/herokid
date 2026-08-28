<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_checkout_reference_counters', function (Blueprint $table): void {
            $table->string('month_key', 2)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        Schema::create('order_checkout_references', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->unique();
            $table->unsignedTinyInteger('reference_month');
            $table->unsignedBigInteger('monthly_sequence');
            $table->string('short_reference', 32)->unique();
            $table->timestamps();
            $table->unique(['reference_month', 'monthly_sequence'], 'order_checkout_ref_month_sequence_unique');
        });

        $counters = [];
        $now = now();

        DB::table('orders')
            ->selectRaw('checkout_group_key, MIN(created_at) as first_created_at, MIN(id) as first_order_id')
            ->whereNotNull('checkout_group_key')
            ->where('checkout_group_key', '!=', '')
            ->groupBy('checkout_group_key')
            ->orderBy('first_created_at')
            ->orderBy('first_order_id')
            ->chunk(500, function ($groups) use (&$counters, $now): void {
                foreach ($groups as $group) {
                    $month = CarbonImmutable::parse($group->first_created_at ?: $now)->format('m');
                    $sequence = ($counters[$month] ?? 0) + 1;
                    $counters[$month] = $sequence;

                    DB::table('order_checkout_references')->insert([
                        'checkout_group_key' => $group->checkout_group_key,
                        'reference_month' => (int) $month,
                        'monthly_sequence' => $sequence,
                        'short_reference' => 'HK'.$month.'-'.$sequence,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        foreach ($counters as $month => $lastSequence) {
            DB::table('order_checkout_reference_counters')->insert([
                'month_key' => $month,
                'last_sequence' => $lastSequence,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_checkout_references');
        Schema::dropIfExists('order_checkout_reference_counters');
    }
};

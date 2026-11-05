<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderProcessEvent;
use App\Models\Product;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Dedicated synthetic MySQL only: this regression needs two committing sessions. */
class PuzzleEnrollmentRaceTest extends TestCase
{
    public function test_enrollment_sees_attempt_committed_after_event_snapshot_before_checkout_lock(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || config('database.connections.mysql.database') !== 'autopilot_synthetic') {
            $this->markTestSkipped('Requires the explicit isolated MySQL fixture database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => false, 'order_process.enabled' => false,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 0]);
        $order = Order::create(['checkout_group_key' => 'synthetic-race', 'order_number' => 'SYN-RACE',
            'parent_name' => 'Synthetic', 'child_name' => 'Synthetic child', 'status' => 'new_confirmed']);
        $product = Product::create(['slug' => 'personalized-child-adventure-hdf-puzzle', 'name_ar' => 'Synthetic',
            'is_active' => true, 'price_cents' => 100]);
        $item = $order->items()->create(['product_id' => $product->id, 'item_type' => 'product', 'title' => 'Synthetic',
            'quantity' => 1, 'unit_price_cents' => 100, 'total_price_cents' => 100]);
        $event = OrderProcessEvent::create(['event_key' => 'synthetic-race-event', 'checkout_key' => 'synthetic-race',
            'order_id' => $order->id, 'type' => 'status_entered', 'status' => 'processed', 'data' => ['status' => 'new_confirmed']]);
        config(['database.connections.enrollment_race' => config('database.connections.mysql')]);
        $other = DB::connection('enrollment_race');
        $isolation = DB::selectOne('SELECT @@transaction_isolation AS level')->level;
        $injected = false;
        DB::listen(function ($query) use (&$injected, $other, $order, $item, $event): void {
            if ($injected || $query->connectionName === 'enrollment_race'
                || ! str_contains($query->sql, 'from `order_process_events`')) {
                return;
            }
            $injected = true;
            $other->transaction(function () use ($other, $order, $item, $event): void {
                $other->table('orders')->where('id', $order->id)->lockForUpdate()->first();
                $other->table('puzzle_generations')->insert(['order_id' => $order->id, 'order_item_id' => $item->id,
                    'active_item_id' => $item->id, 'attempt_number' => 1, 'checkout_key' => 'synthetic-race',
                    'event_id' => $event->id, 'unit_key' => 'product:'.$item->id, 'status' => 'queued',
                    'created_at' => now(), 'updated_at' => now()]);
            });
        });
        try {
            $this->assertSame(0, app(PuzzleAutomation::class)->enroll());
            $this->assertTrue($injected);
            $this->assertDatabaseCount('puzzle_generations', 1);
            $this->assertSame($event->id, (int) DB::table('puzzle_automation_cursors')->value('event_id'));
            $this->assertSame($isolation, DB::selectOne('SELECT @@transaction_isolation AS level')->level);
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            DB::disconnect('enrollment_race');
        }
    }
}

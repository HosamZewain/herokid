<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderProcessEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessEvents
{
    public function checkoutCompleted(Order $order): void
    {
        $this->record($order, 'checkout_created', 'checkout:'.hash('sha256', $order->checkoutGroupKey()), ['status' => $order->status]);
    }

    public function statusChanged(Order $order): void
    {
        if ($order->wasChanged('status') && $order->getRawOriginal('status') !== null) {
            $this->record($order, 'status_entered', (string) Str::uuid(), ['from' => $order->getRawOriginal('status'), 'status' => $order->status]);
        }
    }

    private function record(Order $order, string $type, string $key, array $data): void
    {
        if (! app(ProcessLivePolicy::class)->enabled() || $order->id <= (int) config('order_process.after_order_id')
            || ! app(ProcessLivePolicy::class)->allowsCheckout($order->checkoutGroupKey())) {
            return;
        }
        // Callers must finalize/write the order and outbox in the same transaction.
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('process_event_requires_transaction');
        }
        OrderProcessEvent::firstOrCreate(['event_key' => $key], ['type' => $type, 'order_id' => $order->id,
            'checkout_key' => $order->checkoutGroupKey(), 'data' => $data, 'status' => 'pending']);
    }
}

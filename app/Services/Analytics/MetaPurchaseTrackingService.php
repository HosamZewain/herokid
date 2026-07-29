<?php

namespace App\Services\Analytics;

use App\Jobs\SendMetaConversionEventJob;
use App\Models\MetaConversionEvent;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MetaPurchaseTrackingService
{
    public function record(Request $request, array $orderIds, string $checkoutGroup): array
    {
        $orders = Order::query()
            ->with(['items:id,order_id,item_type,story_id,product_id,sku,title,unit_price_cents,quantity,total_price_cents'])
            ->whereKey($orderIds)
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $firstOrder = $orders->first();
        $customData = $this->customData($orders, $checkoutGroup);
        $eventId = $this->eventId($checkoutGroup);
        $browserEvent = [
            'event_id' => $eventId,
            'currency' => 'EGP',
            'value' => $customData['value'],
            'content_ids' => $customData['content_ids'],
            'content_type' => 'product',
            'num_items' => $customData['num_items'],
            'order_id' => $checkoutGroup,
        ];

        try {
            $event = MetaConversionEvent::query()->firstOrCreate(
                ['checkout_group_key' => $checkoutGroup],
                [
                    'event_id' => $eventId,
                    'event_name' => 'Purchase',
                    'representative_order_id' => $firstOrder->id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'event_time' => now()->timestamp,
                    'event_source_url' => route('checkout.success'),
                    'user_data_encrypted' => $this->userData($request, $firstOrder, $checkoutGroup),
                    'custom_data_json' => $customData,
                ],
            );

            if ($event->wasRecentlyCreated) {
                SendMetaConversionEventJob::dispatch($event->id)->afterCommit();
            }
        } catch (Throwable $exception) {
            Log::warning('Meta Purchase event could not be persisted.', [
                'checkout_group_hash' => hash('sha256', $checkoutGroup),
                'error_type' => $exception::class,
            ]);
        }

        return $browserEvent;
    }

    private function customData(Collection $orders, string $checkoutGroup): array
    {
        $items = $orders->flatMap->items;
        $contents = $items->map(function ($item): array {
            $id = filled($item->sku)
                ? (string) $item->sku
                : ($item->story_id ? 'story-'.$item->story_id : 'product-'.$item->product_id);

            return [
                'id' => $id,
                'quantity' => max(1, (int) $item->quantity),
                'item_price' => round(((int) $item->unit_price_cents) / 100, 2),
            ];
        })->values();
        $firstOrder = $orders->first();
        $itemsTotal = ((int) $items->sum('total_price_cents')) / 100;
        $deliveryFee = max(0, (float) data_get($firstOrder->delivery_details, 'delivery_fee', 0));
        $savedTotal = data_get($firstOrder->delivery_details, 'total');
        $value = is_numeric($savedTotal) ? (float) $savedTotal : $itemsTotal + $deliveryFee;

        return [
            'currency' => 'EGP',
            'value' => round(max(0, $value), 2),
            'order_id' => $checkoutGroup,
            'content_type' => 'product',
            'content_ids' => $contents->pluck('id')->unique()->values()->all(),
            'contents' => $contents->all(),
            'num_items' => (int) $contents->sum('quantity'),
        ];
    }

    private function userData(Request $request, Order $order, string $checkoutGroup): array
    {
        $data = [
            'external_id' => [$this->hash('checkout:'.$checkoutGroup)],
        ];
        $phone = preg_replace('/\D+/', '', (string) data_get($order->delivery_details, 'phone', ''));
        $email = strtolower(trim((string) ($request->user()?->email ?? '')));
        $countryCode = strtolower(trim((string) ($order->delivery_details['country_code'] ?? 'eg')));

        if ($phone !== '') {
            $data['ph'] = [$this->hash($phone)];
        }

        if ($email !== '') {
            $data['em'] = [$this->hash($email)];
        }

        if ($countryCode !== '') {
            $data['country'] = [$this->hash($countryCode)];
        }

        if (filter_var($request->ip(), FILTER_VALIDATE_IP)) {
            $data['client_ip_address'] = $request->ip();
        }

        if (filled($request->userAgent())) {
            $data['client_user_agent'] = Str::limit((string) $request->userAgent(), 500, '');
        }

        foreach (['_fbp' => 'fbp', '_fbc' => 'fbc'] as $cookie => $field) {
            $value = trim((string) $request->cookie($cookie, ''));
            if ($value !== '') {
                $data[$field] = Str::limit($value, 255, '');
            }
        }

        return $data;
    }

    private function eventId(string $checkoutGroup): string
    {
        return 'purchase-'.strtolower($checkoutGroup);
    }

    private function hash(string $value): string
    {
        return hash('sha256', trim($value));
    }
}

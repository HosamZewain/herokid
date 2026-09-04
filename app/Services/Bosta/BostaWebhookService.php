<?php

namespace App\Services\Bosta;

use App\Models\BostaShipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BostaWebhookService
{
    public function __construct(private BostaShippingStatusService $shippingStatuses) {}

    public function handle(array $payload): BostaShipment
    {
        return DB::transaction(function () use ($payload): BostaShipment {
            $shipment = BostaShipment::query()
                ->where(function ($query) use ($payload): void {
                    if (filled($payload['_id'] ?? null)) {
                        $query->orWhere('bosta_delivery_id', $payload['_id']);
                    }
                    if (filled($payload['trackingNumber'] ?? null)) {
                        $query->orWhere('tracking_number', $payload['trackingNumber']);
                    }
                    if (filled($payload['businessReference'] ?? null)) {
                        $query->orWhere('business_reference', $payload['businessReference']);
                    }
                })
                ->lockForUpdate()->firstOrFail();

            $eventKey = hash('sha256', implode('|', [
                $shipment->id,
                $payload['state'] ?? '',
                $payload['timeStamp'] ?? '',
                $payload['numberOfAttempts'] ?? '',
            ]));
            $event = $shipment->events()->firstOrCreate(['event_key' => $eventKey], [
                'state_code' => $payload['state'] ?? null,
                'occurred_at' => $this->occurredAt($payload['timeStamp'] ?? null),
                'payload' => Arr::only($payload, ['_id', 'trackingNumber', 'state', 'type', 'cod', 'timeStamp', 'isConfirmedDelivery', 'deliveryPromiseDate', 'exceptionReason', 'exceptionCode', 'businessReference', 'numberOfAttempts']),
            ]);

            if ($event->wasRecentlyCreated) {
                $behavior = $this->behavior((int) ($payload['state'] ?? 0));
                $shipment->forceFill([
                    'state_code' => $payload['state'] ?? $shipment->state_code,
                    'shipping_status' => $behavior ?: $shipment->shipping_status,
                    'provider_reported_cod_cents' => array_key_exists('cod', $payload) ? (int) round(((float) $payload['cod']) * 100) : $shipment->provider_reported_cod_cents,
                    'delivery_promise_date' => filled($payload['deliveryPromiseDate'] ?? null) ? CarbonImmutable::parse($payload['deliveryPromiseDate']) : $shipment->delivery_promise_date,
                    'number_of_attempts' => (int) ($payload['numberOfAttempts'] ?? $shipment->number_of_attempts),
                    'exception_code' => $payload['exceptionCode'] ?? null,
                    'exception_reason' => $payload['exceptionReason'] ?? null,
                    'is_confirmed_delivery' => $payload['isConfirmedDelivery'] ?? $shipment->is_confirmed_delivery,
                    'last_event_at' => $event->occurred_at,
                ])->save();

                if ($behavior) {
                    $this->shippingStatuses->updateCheckout($shipment->checkout_group_key, $behavior, 'تحديث تلقائي من Bosta (الحالة '.$shipment->state_code.').');
                }
            }

            return $shipment->refresh();
        });
    }

    private function behavior(int $state): ?string
    {
        return match ($state) {
            10, 11, 20 => 'ready',
            21, 22, 23, 24, 25, 30, 40, 41 => 'shipped',
            45 => 'delivered',
            46, 60 => 'returned',
            48, 49 => 'cancelled',
            default => null,
        };
    }

    private function occurredAt(mixed $timestamp): CarbonImmutable
    {
        if (is_numeric($timestamp)) {
            $value = (float) $timestamp;

            return $value > 10_000_000_000
                ? CarbonImmutable::createFromTimestampMs((int) $value)
                : CarbonImmutable::createFromTimestamp((int) $value);
        }

        return filled($timestamp) ? CarbonImmutable::parse($timestamp) : now()->toImmutable();
    }
}

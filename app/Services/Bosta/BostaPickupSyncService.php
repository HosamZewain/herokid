<?php

namespace App\Services\Bosta;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BostaPickupSyncService
{
    public const LAST_SYNC_CACHE_KEY = 'bosta:pickups:last-successful-sync';

    public function __construct(private BostaClient $client) {}

    /** @return array{synced: int, linked_shipments: int, skipped: bool} */
    public function syncIfDue(bool $force = false): array
    {
        if (! config('bosta.pickup_sync_enabled')) {
            return ['synced' => 0, 'linked_shipments' => 0, 'skipped' => true];
        }

        $interval = max(1, (int) config('bosta.pickup_sync_interval_minutes', 5));
        $lastSync = Cache::get(self::LAST_SYNC_CACHE_KEY);
        if (! $force && is_numeric($lastSync) && (int) $lastSync > now()->subMinutes($interval)->timestamp) {
            return ['synced' => 0, 'linked_shipments' => 0, 'skipped' => true];
        }

        $lock = Cache::lock('bosta:pickups:sync-lock', 120);
        if (! $lock->get()) {
            return ['synced' => 0, 'linked_shipments' => 0, 'skipped' => true];
        }

        try {
            $result = $this->sync();
            Cache::put(self::LAST_SYNC_CACHE_KEY, now()->timestamp, now()->addDay());

            return $result + ['skipped' => false];
        } finally {
            $lock->release();
        }
    }

    /** @return array{synced: int, linked_shipments: int} */
    public function sync(): array
    {
        $synced = 0;
        $linkedShipments = 0;
        $page = 0;
        $pageLimit = max(1, (int) config('bosta.pickup_sync_pages', 5));

        do {
            $response = $this->client->searchPickups($page);
            $pickups = data_get($response, 'data.list', []);
            $pages = max(1, (int) data_get($response, 'data.pages', 1));

            foreach (is_array($pickups) ? $pickups : [] as $summary) {
                if (! is_array($summary) || blank($summary['_id'] ?? null)) {
                    continue;
                }

                [$wasSynced, $linked] = $this->syncPickup($summary);
                $synced += $wasSynced ? 1 : 0;
                $linkedShipments += $linked;
            }

            $page++;
        } while ($page < $pages && $page < $pageLimit);

        return ['synced' => $synced, 'linked_shipments' => $linkedShipments];
    }

    /** @return array{bool, int} */
    private function syncPickup(array $summary): array
    {
        $pickupId = (string) $summary['_id'];
        $data = $summary;

        try {
            $detail = $this->client->pickup($pickupId);
            if (is_array(data_get($detail, 'data'))) {
                $data = array_replace($summary, data_get($detail, 'data'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $scheduledDate = $this->scheduledDate($data['scheduledDate'] ?? null);
        $pickupExists = BostaPickup::query()->where('bosta_pickup_id', $pickupId)->exists();
        if (! $pickupExists && ! $scheduledDate) {
            return [false, 0];
        }

        $trackingNumbers = $this->identifiers($data, 'trackingNumber', 'deliveryTrackingNumbers');
        $deliveryIds = $this->identifiers($data, '_id', 'deliveryIds');

        return DB::transaction(function () use ($data, $pickupId, $scheduledDate, $trackingNumbers, $deliveryIds): array {
            $pickup = BostaPickup::query()
                ->where('bosta_pickup_id', $pickupId)
                ->lockForUpdate()
                ->first() ?: new BostaPickup(['uuid' => (string) Str::uuid()]);
            $source = $pickup->created_by_user_id ? 'herokid' : 'bosta_dashboard';
            $providerResponse = Arr::only($data, [
                '_id', 'puid', 'state', 'type', 'scheduledDate', 'scheduledTimeSlot',
                'businessLocationId', 'deliveryIds', 'deliveryTrackingNumbers', 'pickedUpPackages',
            ]);
            $providerResponse['source'] = $source;
            $providerResponse['synced_at'] = now()->toIso8601String();

            $pickup->forceFill([
                'bosta_pickup_id' => $pickupId,
                'scheduled_date' => $scheduledDate ?: $pickup->scheduled_date,
                'business_location_id' => (string) ($data['businessLocationId'] ?? $pickup->business_location_id ?? config('bosta.business_location_id')),
                'contact_name' => (string) data_get($data, 'contactPerson.name', $pickup->contact_name ?: 'Bosta'),
                'contact_phone' => (string) data_get($data, 'contactPerson.phone', $pickup->contact_phone ?: '—'),
                'notes' => $data['notes'] ?? $pickup->notes,
                'number_of_parcels' => (int) ($data['numberOfParcels'] ?? max(count($trackingNumbers), count($deliveryIds), (int) ($data['pickedUpPackages'] ?? 0))),
                'package_type' => (string) ($data['packageType'] ?? $pickup->package_type ?? 'Normal'),
                'status' => (string) ($data['state'] ?? $pickup->status ?? 'requested'),
                'provider_response' => $providerResponse,
            ])->save();

            $shipments = collect();
            if ($trackingNumbers !== [] || $deliveryIds !== []) {
                $shipments = BostaShipment::query()
                    ->where(function ($query) use ($trackingNumbers, $deliveryIds): void {
                        if ($trackingNumbers !== []) {
                            $query->whereIn('tracking_number', $trackingNumbers);
                        }
                        if ($deliveryIds !== []) {
                            $method = $trackingNumbers === [] ? 'whereIn' : 'orWhereIn';
                            $query->{$method}('bosta_delivery_id', $deliveryIds);
                        }
                    })
                    ->get();
            }

            $before = $pickup->shipments()->whereKey($shipments->modelKeys())->count();
            $pickup->shipments()->syncWithoutDetaching($shipments->modelKeys());

            return [true, max(0, $shipments->count() - $before)];
        });
    }

    /** @return array<int, string> */
    private function identifiers(array $data, string $nestedKey, string $listKey): array
    {
        $identifiers = collect(Arr::wrap($data[$listKey] ?? []));

        foreach (Arr::wrap($data['deliveries'] ?? []) as $delivery) {
            $identifiers->push(is_array($delivery) ? ($delivery[$nestedKey] ?? null) : $delivery);
        }

        return $identifiers
            ->filter(fn ($value): bool => is_scalar($value) && filled((string) $value))
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function scheduledDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}

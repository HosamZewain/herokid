<?php

namespace App\Services\Bosta;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BostaPickupService
{
    public function __construct(private BostaClient $client) {}

    public function create(array $shipmentIds, array $values, User $admin, Request $request): BostaPickup
    {
        return DB::transaction(function () use ($shipmentIds, $values, $admin, $request): BostaPickup {
            $shipments = BostaShipment::query()
                ->whereIn('id', $shipmentIds)
                ->whereNotNull('tracking_number')
                ->awaitingPickup()
                ->lockForUpdate()
                ->get();
            if ($shipments->count() !== count(array_unique($shipmentIds))) {
                throw ValidationException::withMessages(['shipments' => 'بعض الشحنات المختارة غير صالحة أو لم تُنشأ في Bosta.']);
            }

            $payload = [
                'scheduledDate' => $values['scheduled_date'],
                'businessLocationId' => (string) config('bosta.business_location_id'),
                'contactPerson' => ['name' => $values['contact_name'], 'phone' => $values['contact_phone']],
                'notes' => $values['notes'] ?? null,
                'numberOfParcels' => $shipments->count(),
                'packageType' => 'Normal',
            ];
            $response = $this->client->createPickup($payload);
            $data = data_get($response, 'data', $response);

            $pickup = BostaPickup::query()->create([
                'uuid' => (string) Str::uuid(),
                'bosta_pickup_id' => data_get($data, '_id') ?: data_get($data, 'id'),
                'scheduled_date' => $values['scheduled_date'],
                'business_location_id' => config('bosta.business_location_id'),
                'contact_name' => $values['contact_name'],
                'contact_phone' => $values['contact_phone'],
                'notes' => $values['notes'] ?? null,
                'number_of_parcels' => $shipments->count(),
                'package_type' => 'Normal',
                'provider_response' => collect($data)->only(['_id', 'id', 'state', 'scheduledDate'])
                    ->put('source', 'herokid')
                    ->all(),
                'created_by_user_id' => $admin->id,
            ]);
            $pickup->shipments()->sync($shipments->modelKeys());

            AdminActivityLogger::log('bosta.pickup.created', 'تم طلب استلام Bosta لعدد '.$shipments->count().' شحنة.', $pickup, [
                'shipment_ids' => $shipments->modelKeys(),
            ], $admin, $request);

            return $pickup->load('shipments');
        });
    }
}

<?php

namespace App\Services\Bosta;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\Phone;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaPickupService
{
    public function __construct(private BostaClient $client, private BostaPickupSyncService $sync) {}

    public function create(array $shipmentIds, array $values, ?User $admin, ?Request $request, ?string $requestKey = null): BostaPickup
    {
        $location = (string) config('bosta.business_location_id');
        $phone = Phone::forWhatsApp($values['contact_phone'] ?? null);
        if (! $location || ! preg_match('/^201[0125]\d{8}$/', (string) $phone)) {
            throw ValidationException::withMessages(['contact_phone' => 'حدد رقم موبايل مصري صحيح ومكان الاستلام.']);
        }
        $phone = '0'.substr($phone, 2);
        $lock = Cache::lock('bosta:pickup-create:'.$location, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['shipments' => 'طلب استلام آخر جارٍ الآن. انتظر تحديث النتيجة.']);
        }
        try {
            if (BostaShipment::query()->whereIn('id', $shipmentIds)->whereNotNull('tracking_number')->where('business_location_id', $location)->awaitingPickup()->count() !== count(array_unique($shipmentIds)) || $shipmentIds === []) {
                throw ValidationException::withMessages(['shipments' => 'الشحنات المختارة غير مؤهلة لاستلام جديد.']);
            }
            // Check dashboard-created pickups before making a billable request.
            if (config('bosta.pickup_sync_enabled')) {
                $this->sync->sync();
            }
            $available = $this->client->availablePickupDates();
            $dates = data_get($available, 'data', []);
            if (! is_array($dates) || ! in_array($values['scheduled_date'], $dates, true)) {
                throw ValidationException::withMessages(['scheduled_date' => 'اليوم غير متاح للاستلام لدى Bosta. اختر يومًا آخر؛ لم يتم إرسال طلب استلام.']);
            }
            if (data_get($this->client->pickupCreationValidity(), 'data.valid') !== true) {
                throw ValidationException::withMessages(['shipments' => 'Bosta لا تسمح بطلب استلام جديد حاليًا. راجع الطلبات الموجودة ومكان الاستلام.']);
            }
            $pickup = DB::transaction(function () use ($shipmentIds, $values, $admin, $location, $phone, $requestKey): BostaPickup {
                if (BostaPickup::query()->where('business_location_id', $location)
                    ->whereDate('scheduled_date', $values['scheduled_date'])->active()->exists()
                    || ($requestKey && BostaPickup::where('request_key', $requestKey)->exists())) {
                    throw ValidationException::withMessages(['shipments' => 'يوجد طلب استلام لهذا المكان واليوم، أو محاولة تحتاج مراجعة. راجع سجل الاستلام لمنع التكرار.']);
                }
                $shipments = BostaShipment::query()->whereIn('id', $shipmentIds)->whereNotNull('tracking_number')
                    ->where('business_location_id', $location)->awaitingPickup()->lockForUpdate()->get();
                if ($shipments->isEmpty() || $shipments->count() !== count(array_unique($shipmentIds))) {
                    throw ValidationException::withMessages(['shipments' => 'بعض الشحنات المختارة غير جاهزة، أو ضمن استلام سابق، أو في مكان استلام مختلف.']);
                }
                $pickup = BostaPickup::query()->create([
                    'uuid' => (string) Str::uuid(), 'request_key' => $requestKey,
                    'active_slot_key' => hash('sha256', $location.'|'.$values['scheduled_date']),
                    'scheduled_date' => $values['scheduled_date'], 'business_location_id' => $location,
                    'contact_name' => $values['contact_name'], 'contact_phone' => $phone,
                    'notes' => $values['notes'] ?? null, 'number_of_parcels' => $shipments->count(),
                    'package_type' => 'Normal', 'status' => 'submitting', 'created_by_user_id' => $admin?->id,
                    'provider_response' => ['source' => $requestKey ? 'herokid_schedule' : 'herokid'],
                ]);
                $pickup->shipments()->sync($shipments->modelKeys());

                return $pickup->load('shipments');
            });
            try {
                $response = $this->client->createPickup(array_filter([
                    'scheduledDate' => $values['scheduled_date'], 'businessLocationId' => $location,
                    'contactPerson' => ['name' => $values['contact_name'], 'phone' => $phone],
                    'notes' => $values['notes'] ?? null, 'numberOfParcels' => $pickup->number_of_parcels,
                    'packageType' => 'Normal', 'trackingNumbers' => $pickup->shipments->pluck('tracking_number')->all(),
                ], fn ($value) => $value !== null));
                $data = data_get($response, 'data', []);
                $id = data_get($data, '_id') ?: data_get($data, 'id');
                if (($response['success'] ?? true) !== true || blank($id)) {
                    throw new \RuntimeException('Pickup result requires reconciliation.');
                }
                $pickup->update(['bosta_pickup_id' => $id, 'status' => $data['state'] ?? 'requested']);
            } catch (Throwable $exception) {
                $status = $exception instanceof RequestException ? $exception->response->status() : null;
                $definitive = in_array($status, [400, 401, 403, 422], true);
                $pickup->update([
                    'status' => $definitive ? 'failed' : 'uncertain',
                    'active_slot_key' => $definitive ? null : $pickup->active_slot_key,
                    'last_error' => $status ? 'Provider HTTP '.$status : 'Provider result uncertain',
                ]);
                // Do not persist provider payloads containing customer data in error logs.
                throw ValidationException::withMessages(['shipments' => $definitive
                    ? 'رفضت Bosta طلب الاستلام (HTTP '.$status.'). راجع اليوم المتاح وبيانات المسؤول ومكان الاستلام. رقم المحاولة: '.$pickup->id
                    : 'نتيجة طلب الاستلام غير مؤكدة؛ لن نكرره تلقائيًا. راجع Bosta وطابق المحاولة رقم '.$pickup->id]);
            }
            AdminActivityLogger::log('bosta.pickup.created', 'تم طلب استلام Bosta لعدد '.$pickup->number_of_parcels.' شحنة.', $pickup, [
                'shipment_ids' => $pickup->shipments->modelKeys(),
            ], $admin, $request);

            return $pickup;
        } finally {
            $lock->release();
        }
    }
}

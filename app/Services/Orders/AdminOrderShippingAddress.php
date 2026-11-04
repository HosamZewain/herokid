<?php

namespace App\Services\Orders;

use App\Models\DeliveryGovernorate;
use App\Services\Bosta\BostaAddressCatalogService;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Order address only: never changes an existing provider shipment or payment totals. */
class AdminOrderShippingAddress
{
    public function __construct(private BostaAddressCatalogService $catalog) {}

    public function normalize(array $input): array
    {
        try {
            $city = $this->catalog->findCityById($input['bosta_city_id']);
            $district = $city ? $this->catalog->findDistrictById($city['id'], $input['bosta_district_id']) : null;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['shipping_address.bosta_district_id' => 'تعذر التحقق من عنوان بوسطة. لم يتم حفظ التعديل؛ حاول مرة أخرى.']);
        }
        if (! $city || ! $district) {
            throw ValidationException::withMessages(['shipping_address.bosta_district_id' => 'اختر محافظة ومنطقة متطابقتين من قوائم بوسطة.']);
        }
        $governorate = DeliveryGovernorate::query()->with('country')->whereHas('country', fn ($q) => $q->where('code', 'EG'))->get()
            ->first(fn ($local) => $this->catalog->findCityByName($local->name, [$city]) !== null);

        return [
            'governorate' => $city['other_name'] ?: $city['name'],
            'delivery_governorate_id' => $governorate?->id,
            'country' => $governorate?->country?->name ?? 'مصر',
            'delivery_country_id' => $governorate?->delivery_country_id,
            'city' => $district['other_name'] ?: $district['name'],
            'area' => $district['other_name'] ?: $district['name'],
            'street' => trim($input['street']),
            'address' => trim($input['street']),
            'address_details' => trim($input['address_details'] ?? ''),
            'bosta_city_id' => $city['id'],
            'bosta_city_name' => $city['name'],
            'bosta_city_other_name' => $city['other_name'] ?: null,
            'bosta_district_id' => $district['id'],
            'bosta_district_name' => $district['name'],
            'bosta_district_other_name' => $district['other_name'] ?: null,
            'bosta_zone_id' => $district['zone_id'] ?: null,
            'bosta_zone_name' => $district['zone_name'] ?: null,
            'bosta_zone_other_name' => $district['zone_other_name'] ?: null,
        ];
    }
}

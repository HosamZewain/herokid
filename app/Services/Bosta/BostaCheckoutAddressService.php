<?php

namespace App\Services\Bosta;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaCheckoutAddressService
{
    public function __construct(private BostaAddressCatalogService $catalog) {}

    public function configured(): bool
    {
        return (bool) config('bosta.enabled')
            && filled(config('bosta.api_key'))
            && filled(config('bosta.country_id'));
    }

    /**
     * @param  Collection<int, DeliveryCountry>  $countries
     * @return array{enabled:bool,city_map:array<int, array{id:string,label:string}>}
     */
    public function checkoutOptions(Collection $countries): array
    {
        if (! $this->configured()) {
            return ['enabled' => false, 'city_map' => []];
        }

        try {
            $cities = $this->catalog->cities();
            $cityMap = [];

            foreach ($countries->where('code', 'EG') as $country) {
                foreach ($country->activeGovernorates as $governorate) {
                    $city = $this->catalog->findCityByName($governorate->name, $cities);

                    if ($city) {
                        $cityMap[$governorate->id] = [
                            'id' => $city['id'],
                            'label' => $city['label'],
                        ];
                    }
                }
            }

            return [
                'enabled' => $cityMap !== [],
                'city_map' => $cityMap,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['enabled' => false, 'city_map' => []];
        }
    }

    /**
     * Validate the customer's official Bosta area selection and return fields
     * that can be merged into the immutable order delivery snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public function normalizeForCheckout(
        DeliveryCountry $country,
        DeliveryGovernorate $governorate,
        array $data,
    ): array {
        $legacyCity = trim((string) ($data['city'] ?? ''));

        if (! $this->configured() || $country->code !== 'EG') {
            return ['city' => $this->requireLegacyCity($legacyCity)];
        }

        try {
            $city = $this->catalog->findCityByName($governorate->name);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'bosta_district_id' => 'تعذر التحقق من المناطق الآن. حاول مرة أخرى بعد قليل.',
            ]);
        }

        // An unmapped local governorate keeps the legacy text-address path so
        // checkout is not blocked by an incomplete provider catalog.
        if (! $city) {
            return ['city' => $this->requireLegacyCity($legacyCity)];
        }

        $cityId = trim((string) ($data['bosta_city_id'] ?? ''));
        $districtId = trim((string) ($data['bosta_district_id'] ?? ''));

        if ($cityId === '' || $districtId === '') {
            throw ValidationException::withMessages([
                'bosta_district_id' => 'اختر المنطقة بعد اختيار المحافظة.',
            ]);
        }

        if (! hash_equals($city['id'], $cityId)) {
            throw ValidationException::withMessages([
                'bosta_district_id' => 'المنطقة المختارة لا تتبع المحافظة المحددة.',
            ]);
        }

        try {
            $district = $this->catalog->findDistrictById($cityId, $districtId);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'bosta_district_id' => 'تعذر التحقق من المنطقة الآن. حاول مرة أخرى بعد قليل.',
            ]);
        }

        if (! $district) {
            throw ValidationException::withMessages([
                'bosta_district_id' => 'اختر منطقة صحيحة من القائمة.',
            ]);
        }

        return [
            'city' => $district['other_name'] ?: $district['name'],
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

    private function requireLegacyCity(string $city): string
    {
        if ($city === '') {
            throw ValidationException::withMessages([
                'city' => 'اكتب المدينة أو المنطقة.',
            ]);
        }

        return $city;
    }
}

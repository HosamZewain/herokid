<?php

namespace App\Services\Bosta;

use Illuminate\Validation\ValidationException;

class BostaAddressResolver
{
    public function __construct(private BostaAddressCatalogService $catalog) {}

    /** @return array{city:string,cityId:string,districtId?:string,districtName?:string,firstLine:string,secondLine:?string} */
    public function resolve(array $delivery): array
    {
        $selectedCityId = trim((string) data_get($delivery, 'bosta_city_id'));
        $selectedDistrictId = trim((string) data_get($delivery, 'bosta_district_id'));
        $governorate = trim((string) data_get($delivery, 'governorate'));
        $district = trim((string) (data_get($delivery, 'city') ?: data_get($delivery, 'area')));
        $firstLine = trim((string) (data_get($delivery, 'street') ?: data_get($delivery, 'address')));
        $secondLine = trim((string) data_get($delivery, 'address_details'));

        if (mb_strlen($firstLine) < 6 || (($selectedCityId === '' || $selectedDistrictId === '') && ($governorate === '' || $district === ''))) {
            throw ValidationException::withMessages([
                'order' => 'بيانات المحافظة أو المدينة أو عنوان الشارع غير مكتملة لإنشاء شحنة Bosta.',
            ]);
        }

        if ($selectedCityId !== '' || $selectedDistrictId !== '') {
            if ($selectedCityId === '' || $selectedDistrictId === '') {
                throw ValidationException::withMessages([
                    'bosta_district_id' => 'يجب اختيار محافظة ومنطقة Bosta معًا.',
                ]);
            }

            $city = $this->catalog->findCityById($selectedCityId);
            $selectedDistrict = $city ? $this->catalog->findDistrictById($selectedCityId, $selectedDistrictId) : null;
            if (! $city || ! $selectedDistrict) {
                throw ValidationException::withMessages([
                    'bosta_district_id' => 'المنطقة المختارة غير معتمدة لدى Bosta أو لا تتبع المحافظة المختارة.',
                ]);
            }

            return [
                'city' => $city['name'],
                'cityId' => $city['id'],
                'districtId' => $selectedDistrict['id'],
                'firstLine' => $firstLine,
                'secondLine' => $secondLine !== '' ? $secondLine : null,
            ];
        }

        $city = $this->catalog->findCityByName($governorate);

        if (! $city) {
            throw ValidationException::withMessages([
                'order' => 'تعذر مطابقة المحافظة مع مدن Bosta. راجع عنوان الطلب أو إعدادات Bosta.',
            ]);
        }

        return [
            'city' => $city['name'] ?: $governorate,
            'cityId' => $city['id'],
            'districtName' => $district,
            'firstLine' => $firstLine,
            'secondLine' => $secondLine !== '' ? $secondLine : null,
        ];
    }
}

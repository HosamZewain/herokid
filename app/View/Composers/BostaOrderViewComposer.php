<?php

namespace App\View\Composers;

use App\Models\BostaShipment;
use App\Services\Bosta\BostaAddressCatalogService;
use App\Services\Bosta\BostaShipmentEligibilityService;
use Illuminate\View\View;
use Throwable;

class BostaOrderViewComposer
{
    public function __construct(
        private BostaShipmentEligibilityService $eligibility,
        private BostaAddressCatalogService $catalog,
    ) {}

    public function compose(View $view): void
    {
        $data = $view->getData();
        $group = $data['group'] ?? $data['checkoutGroup'] ?? null;
        $configured = config('bosta.enabled')
            && filled(config('bosta.api_key'))
            && filled(config('bosta.business_location_id'));

        if (! is_array($group) || blank($group['key'] ?? null)) {
            $view->with([
                'bostaShipment' => null,
                'bostaEligible' => false,
                'bostaConfigured' => $configured,
                'bostaCities' => [],
                'bostaDistricts' => [],
                'bostaSelectedCityId' => null,
                'bostaSelectedDistrictId' => null,
                'bostaAddressCatalogAvailable' => false,
            ]);

            return;
        }

        $shipment = BostaShipment::query()
            ->with('pickups')
            ->where('checkout_group_key', $group['key'])
            ->latest()
            ->first();
        $eligible = $this->eligibility->isEligible($group['active_orders']);
        $cities = [];
        $districts = [];
        $selectedCityId = session()->getOldInput('bosta_city_id');
        $selectedDistrictId = session()->getOldInput('bosta_district_id');
        $catalogAvailable = false;

        if ($configured && $eligible && (! $shipment || $shipment->creation_status === 'failed')) {
            try {
                $cities = $this->catalog->cities();
                $matchedCity = $selectedCityId
                    ? $this->catalog->findCityById((string) $selectedCityId)
                    : $this->catalog->findCityByName((string) data_get($group, 'delivery.governorate'));
                $selectedCityId = $matchedCity['id'] ?? null;
                if ($selectedCityId) {
                    $districts = $this->catalog->districts((string) $selectedCityId);
                    if (! $selectedDistrictId) {
                        $candidate = (string) (data_get($group, 'delivery.city') ?: data_get($group, 'delivery.area'));
                        $selectedDistrictId = $this->catalog->findDistrictByName((string) $selectedCityId, $candidate)['id'] ?? null;
                    }
                }
                $catalogAvailable = $cities !== [];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $view->with([
            'bostaShipment' => $shipment,
            'bostaEligible' => $eligible,
            'bostaConfigured' => $configured,
            'bostaCities' => $cities,
            'bostaDistricts' => $districts,
            'bostaSelectedCityId' => $selectedCityId,
            'bostaSelectedDistrictId' => $selectedDistrictId,
            'bostaAddressCatalogAvailable' => $catalogAvailable,
        ]);
    }
}

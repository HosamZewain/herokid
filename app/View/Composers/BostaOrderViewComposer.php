<?php

namespace App\View\Composers;

use App\Models\BostaShipment;
use App\Services\Bosta\BostaShipmentEligibilityService;
use Illuminate\View\View;

class BostaOrderViewComposer
{
    public function __construct(private BostaShipmentEligibilityService $eligibility) {}

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
            ]);

            return;
        }

        $view->with([
            'bostaShipment' => BostaShipment::query()
                ->with('pickups')
                ->where('checkout_group_key', $group['key'])
                ->latest()
                ->first(),
            'bostaEligible' => $this->eligibility->isEligible($group['active_orders']),
            'bostaConfigured' => $configured,
        ]);
    }
}

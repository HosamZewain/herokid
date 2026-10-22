<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Cart\FreeShippingOfferService;
use App\Support\AdminActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FreeShippingOfferController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('shippingOffer', [
            'enabled' => ['required', 'boolean'],
            'minimum_subtotal' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
        ]);
        $configuration = [
            'enabled' => $request->boolean('enabled'),
            'minimum_cents' => (int) round($data['minimum_subtotal'] * 100),
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], 'Africa/Cairo')->startOfMinute()->utc()->toISOString(),
            'ends_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['ends_at'], 'Africa/Cairo')->startOfMinute()->utc()->toISOString(),
        ];
        $before = app(FreeShippingOfferService::class)->configuration();
        $setting = Setting::updateOrCreate(['key' => FreeShippingOfferService::KEY], [
            'value' => json_encode($configuration, JSON_THROW_ON_ERROR),
            'updated_by' => $request->user()->id,
        ]);
        AdminActivityLogger::log('free_shipping_offer.updated', 'تعديل عرض الشحن المجاني للموقع.', $setting,
            ['changes' => AdminActivityLogger::changedValues($before, $configuration)], request: $request);

        return redirect()->route('admin.discount-codes.index')->with('success', 'تم حفظ عرض الشحن المجاني. يُطبق تلقائيًا خلال المدة المحددة.');
    }
}

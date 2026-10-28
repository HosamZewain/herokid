<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Cart\ShippingDiscountService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingDiscountController extends Controller
{
    public function update(Request $request, ShippingDiscountService $discount): RedirectResponse
    {
        $data = $request->validateWithBag('shippingDiscount', [
            'shipping_enabled' => ['required', 'boolean'],
            'shipping_type' => ['required', Rule::in(['fixed', 'percent'])],
            'shipping_value' => ['required', 'numeric', 'decimal:0,2', 'min:0',
                $request->input('shipping_type') === 'percent' ? 'max:100' : 'max:100000'],
        ], [], ['shipping_enabled' => 'تفعيل خصم الشحن', 'shipping_type' => 'نوع خصم الشحن', 'shipping_value' => 'قيمة خصم الشحن']);
        $before = $discount->configuration();
        $configuration = ['enabled' => $request->boolean('shipping_enabled'),
            'type' => $data['shipping_type'], 'value' => (int) round($data['shipping_value'] * 100)];
        $setting = Setting::updateOrCreate(['key' => ShippingDiscountService::KEY], [
            'value' => json_encode($configuration, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->id,
        ]);
        AdminActivityLogger::log('shipping_discount.updated', 'تعديل الخصم العام على الشحن.', $setting,
            ['changes' => AdminActivityLogger::changedValues($before, $configuration)], request: $request);

        return redirect()->route('admin.discount-codes.index')->with('success', 'تم حفظ خصم الشحن. يُطبق على الطلبات الجديدة بالموقع والتطبيق دون تغيير الطلبات السابقة.');
    }
}

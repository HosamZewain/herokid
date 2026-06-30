<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryZoneController extends Controller
{
    public function index()
    {
        $countries = DeliveryCountry::with(['governorates' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        return view('admin.delivery-zones.index', compact('countries'));
    }

    public function storeCountry(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:delivery_countries,name',
            'code' => 'nullable|string|max:3|unique:delivery_countries,code',
            'delivery_fee' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        DeliveryCountry::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ? strtoupper($validated['code']) : null,
            'delivery_fee' => $validated['delivery_fee'],
            'active' => $request->boolean('active', true),
        ]);

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تمت إضافة الدولة بنجاح.');
    }

    public function updateCountry(Request $request, DeliveryCountry $country)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('delivery_countries', 'name')->ignore($country)],
            'code' => ['nullable', 'string', 'max:3', Rule::unique('delivery_countries', 'code')->ignore($country)],
            'delivery_fee' => 'required|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        $country->update([
            'name' => $validated['name'],
            'code' => $validated['code'] ? strtoupper($validated['code']) : null,
            'delivery_fee' => $validated['delivery_fee'],
            'active' => $request->boolean('active'),
        ]);

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تم تحديث الدولة بنجاح.');
    }

    public function destroyCountry(DeliveryCountry $country)
    {
        $country->delete();

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تم حذف الدولة ومناطقها.');
    }

    public function storeGovernorate(Request $request)
    {
        $validated = $request->validate([
            'delivery_country_id' => 'required|exists:delivery_countries,id',
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('delivery_governorates', 'name')
                    ->where(fn ($query) => $query->where('delivery_country_id', $request->input('delivery_country_id'))),
            ],
            'delivery_fee' => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        DeliveryGovernorate::create([
            'delivery_country_id' => $validated['delivery_country_id'],
            'name' => $validated['name'],
            'delivery_fee' => $validated['delivery_fee'] ?? null,
            'active' => $request->boolean('active', true),
        ]);

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تمت إضافة المحافظة بنجاح.');
    }

    public function updateGovernorate(Request $request, DeliveryGovernorate $governorate)
    {
        $validated = $request->validate([
            'delivery_country_id' => 'required|exists:delivery_countries,id',
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('delivery_governorates', 'name')
                    ->where(fn ($query) => $query->where('delivery_country_id', $request->input('delivery_country_id')))
                    ->ignore($governorate),
            ],
            'delivery_fee' => 'nullable|numeric|min:0',
            'active' => 'nullable|boolean',
        ]);

        $governorate->update([
            'delivery_country_id' => $validated['delivery_country_id'],
            'name' => $validated['name'],
            'delivery_fee' => $validated['delivery_fee'] ?? null,
            'active' => $request->boolean('active'),
        ]);

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تم تحديث المحافظة بنجاح.');
    }

    public function destroyGovernorate(DeliveryGovernorate $governorate)
    {
        $governorate->delete();

        return redirect()->route('admin.delivery-zones.index')->with('success', 'تم حذف المحافظة.');
    }
}

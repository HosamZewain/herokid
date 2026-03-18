<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PricingPackage;
use Illuminate\Http\Request;

class PricingPackageController extends Controller
{
    public function index()
    {
        $packages = PricingPackage::ordered()->get();
        return view('admin.pricing.index', compact('packages'));
    }

    public function create()
    {
        return view('admin.pricing.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['features'] = $this->parseFeatures($request->input('features_raw', ''));
        PricingPackage::create($data);
        return redirect()->route('admin.pricing.index')->with('success', 'تم إضافة الباقة بنجاح.');
    }

    public function edit(PricingPackage $pricing)
    {
        return view('admin.pricing.edit', ['package' => $pricing]);
    }

    public function update(Request $request, PricingPackage $pricing)
    {
        $data = $this->validated($request);
        $data['features'] = $this->parseFeatures($request->input('features_raw', ''));
        $pricing->update($data);
        return redirect()->route('admin.pricing.index')->with('success', 'تم تحديث الباقة بنجاح.');
    }

    public function destroy(PricingPackage $pricing)
    {
        $pricing->delete();
        return redirect()->route('admin.pricing.index')->with('success', 'تم حذف الباقة.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:255',
            'subtitle'    => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price'       => 'required|numeric|min:0',
            'currency'    => 'nullable|string|max:20',
            'is_featured' => 'boolean',
            'badge'       => 'nullable|string|max:100',
            'button_text' => 'nullable|string|max:100',
            'sort_order'  => 'integer|min:0',
            'active'      => 'boolean',
        ]) + [
            'is_featured' => false,
            'active'      => false,
        ];
    }

    private function parseFeatures(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $raw))
        ));
    }
}

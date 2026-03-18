<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'settings'                => 'required|array',
            'settings.site_name'      => 'required|string|max:100',
            'settings.site_email'     => 'required|email',
            'settings.whatsapp_number'=> 'required|string|max:20',
            'settings.price_soft_cover' => 'required|numeric|min:1',
            'settings.price_hard_cover' => 'required|numeric|min:1',
        ]);

        foreach ($request->input('settings', []) as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        // Save age ranges as a JSON array
        $ageRanges = array_values(array_filter($request->input('age_ranges', [])));
        Setting::updateOrCreate(['key' => 'age_ranges'], ['value' => json_encode($ageRanges, JSON_UNESCAPED_UNICODE)]);

        // Bust the cache so front-end picks up new values immediately
        Cache::forget('site_settings');

        return redirect()->route('admin.settings.index')->with('success', 'تم حفظ الإعدادات بنجاح!');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Images\SiteLogoUpload;
use App\Support\AdminActivityLogger;
use App\Support\RequestSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');

        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request, SiteLogoUpload $logos)
    {
        $settingsInput = $request->input('settings', []);
        $homepageSectionSettings = collect(config('homepage.sections', []))
            ->pluck('setting')
            ->filter()
            ->values()
            ->all();
        $before = Setting::whereIn('key', array_keys($settingsInput))
            ->pluck('value', 'key')
            ->toArray();

        $rules = [
            'site_logo_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120', 'dimensions:min_width=64,min_height=24,max_width=4096,max_height=4096', 'prohibited_if:reset_site_logo,1'],
            'reset_site_logo' => ['nullable', 'boolean'],
            'settings.site_logo' => ['prohibited'],
            'settings' => 'required|array',
            'settings.site_name' => 'required|string|max:100',
            'settings.site_email' => 'required|email',
            'settings.whatsapp_number' => 'required|string|max:20',
            'settings.price_soft_cover' => 'required|numeric|min:1',
            'settings.price_hard_cover' => 'required|numeric|min:1',
            'settings.story_global_price_enabled' => 'nullable|boolean',
            'settings.story_regular_price' => [
                'nullable',
                Rule::requiredIf(fn () => $request->boolean('settings.story_global_price_enabled')),
                'numeric',
                'min:1',
            ],
            'settings.story_offer_enabled' => 'nullable|boolean',
            'settings.story_offer_price' => [
                'nullable',
                Rule::requiredIf(fn () => $request->boolean('settings.story_offer_enabled')),
                'numeric',
                'min:1',
                'lt:settings.story_regular_price',
            ],
            'settings.story_offer_label' => 'nullable|string|max:50',
            'settings.currency_label' => 'sometimes|required|string|max:20',
            'settings.delivery_days_min' => 'sometimes|required|integer|min:1',
            'settings.delivery_days_max' => 'sometimes|required|integer|min:1',
            'settings.shipping_coverage_text' => 'sometimes|required|string|max:255',
            'settings.payment_methods' => 'nullable|string|max:2000',
            'settings.shop_enabled' => 'nullable|boolean',
            'settings.production_layout_website' => 'nullable|string|max:255',
            'settings.production_back_cover_text' => 'nullable|string|max:1000',
            'settings.production_cover_subtitle_template' => 'nullable|string|max:255',
            'settings.home_child_identity_title' => 'nullable|string|max:120',
            'settings.home_workshop_title_1' => 'nullable|string|max:100',
            'settings.home_workshop_title_2' => 'nullable|string|max:100',
            'settings.home_workshop_subtitle' => 'nullable|string|max:400',
            'settings.home_child_identity_subtitle' => 'nullable|string|max:400',
            'settings.home_child_identity_cta' => 'nullable|string|max:60',
            'settings.img_home_child_identity' => 'nullable|string|max:2048',
        ];

        foreach ($homepageSectionSettings as $settingKey) {
            $rules["settings.{$settingKey}"] = 'nullable|boolean';
        }

        $request->validate($rules, [
            'site_logo_file.prohibited_if' => 'اختر رفع لوجو جديد أو الرجوع للوجو الافتراضي، وليس الخيارين معًا.',
        ], ['site_logo_file' => 'لوجو الموقع']);

        if ($request->hasFile('site_logo_file') || $request->boolean('reset_site_logo')) {
            $before['site_logo'] = Setting::where('key', 'site_logo')->value('value');
            $settingsInput['site_logo'] = $request->hasFile('site_logo_file')
                ? json_encode($logos->store($request->file('site_logo_file')), JSON_THROW_ON_ERROR)
                : '';
        }

        if ($request->has('settings.shop_enabled')) {
            $settingsInput['shop_enabled'] = $request->boolean('settings.shop_enabled') ? '1' : '0';
        }

        foreach (array_merge(
            ['story_global_price_enabled', 'story_offer_enabled'],
            $homepageSectionSettings,
        ) as $booleanSetting) {
            if (array_key_exists($booleanSetting, $settingsInput)) {
                $settingsInput[$booleanSetting] = $request->boolean("settings.{$booleanSetting}") ? '1' : '0';
            }
        }

        if (array_key_exists('payment_methods', $settingsInput)) {
            $methods = collect(preg_split('/\r\n|\r|\n/', (string) $settingsInput['payment_methods']))
                ->map(fn ($method) => trim($method))
                ->filter()
                ->values()
                ->all();

            $settingsInput['payment_methods'] = json_encode($methods, JSON_UNESCAPED_UNICODE);
        }

        foreach ($settingsInput as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        // Save age ranges as a JSON array
        $ageRanges = array_values(array_filter($request->input('age_ranges', [])));
        Setting::updateOrCreate(['key' => 'age_ranges'], ['value' => json_encode($ageRanges, JSON_UNESCAPED_UNICODE)]);

        // Bust the cache so front-end picks up new values immediately
        RequestSettings::forget();

        $after = Setting::whereIn('key', array_keys($settingsInput))
            ->pluck('value', 'key')
            ->toArray();

        AdminActivityLogger::log(
            action: 'settings.updated',
            description: 'تحديث إعدادات الموقع.',
            properties: [
                'changed_settings' => AdminActivityLogger::changedValues($before, $after),
                'age_ranges_count' => count($ageRanges),
            ],
            request: $request,
        );

        return redirect()->route('admin.settings.index')->with('success', 'تم حفظ الإعدادات بنجاح!');
    }
}

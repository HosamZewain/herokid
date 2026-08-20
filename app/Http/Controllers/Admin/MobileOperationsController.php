<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceInstallation;
use App\Models\MobileAnalyticsEvent;
use App\Models\MobileCart;
use App\Models\MobileCheckoutAttempt;
use App\Models\MobilePromoCode;
use App\Models\PrivacyRequest;
use App\Models\Setting;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class MobileOperationsController extends Controller
{
    private const CONFIG_KEYS = [
        'mobile_minimum_supported_version', 'mobile_latest_version', 'mobile_force_update',
        'mobile_maintenance_mode', 'mobile_home_banner_title_ar', 'mobile_home_banner_title_en',
        'mobile_home_banner_subtitle_ar', 'mobile_home_banner_subtitle_en',
        'mobile_home_banner_image_url', 'mobile_home_banner_deep_link',
    ];

    public function index()
    {
        $settings = Setting::query()->whereIn('key', self::CONFIG_KEYS)->pluck('value', 'key');
        $since = now()->subDays(30);
        $events = MobileAnalyticsEvent::query()->where('occurred_at', '>=', $since)
            ->selectRaw('event_name, COUNT(*) as aggregate')->groupBy('event_name')->pluck('aggregate', 'event_name');
        $stats = [
            'active_devices' => DeviceInstallation::query()->whereNull('revoked_at')->where('last_seen_at', '>=', $since)->count(),
            'abandoned_carts' => MobileCart::query()->where('status', 'active')->where('last_activity_at', '<=', now()->subHours(6))->count(),
            'completed_checkouts' => MobileCheckoutAttempt::query()->where('status', 'completed')->where('completed_at', '>=', $since)->count(),
            'pending_privacy' => PrivacyRequest::query()->whereIn('status', ['pending', 'in_progress'])->count(),
        ];
        $promoCodes = MobilePromoCode::query()->latest()->limit(50)->get();
        $privacyRequests = PrivacyRequest::query()->with('user:id,name,email,phone')->latest('requested_at')->limit(50)->get();

        return view('admin.mobile-operations.index', compact('settings', 'events', 'stats', 'promoCodes', 'privacyRequests'));
    }

    public function updateConfig(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mobile_minimum_supported_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'mobile_latest_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'mobile_force_update' => ['required', 'boolean'],
            'mobile_maintenance_mode' => ['required', 'boolean'],
            'mobile_home_banner_title_ar' => ['required', 'string', 'max:120'],
            'mobile_home_banner_title_en' => ['required', 'string', 'max:120'],
            'mobile_home_banner_subtitle_ar' => ['nullable', 'string', 'max:300'],
            'mobile_home_banner_subtitle_en' => ['nullable', 'string', 'max:300'],
            'mobile_home_banner_image_url' => ['nullable', 'url:https', 'max:2048'],
            'mobile_home_banner_deep_link' => ['required', 'regex:/^\/[A-Za-z0-9_?&=\/.-]*$/', 'max:255'],
        ]);
        $before = Setting::query()->whereIn('key', self::CONFIG_KEYS)->pluck('value', 'key')->all();
        foreach ($data as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => is_bool($value) ? (int) $value : ($value ?? '')]);
        }
        Cache::forget('site_settings');
        AdminActivityLogger::log('mobile.config.updated', 'تحديث إعدادات تطبيق الهاتف.', properties: [
            'changed_settings' => AdminActivityLogger::changedValues($before, $data),
        ], request: $request);

        return back()->with('success', 'تم تحديث إعدادات التطبيق.');
    }

    public function storePromo(Request $request): RedirectResponse
    {
        $data = $this->promoData($request);
        $promo = MobilePromoCode::query()->create($this->normalizedPromo($data));
        AdminActivityLogger::log('mobile.promo.created', 'إضافة كود خصم لتطبيق الهاتف.', $promo, request: $request);

        return back()->with('success', 'تم إنشاء كود الخصم.');
    }

    public function updatePromo(Request $request, MobilePromoCode $promoCode): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $promoCode->update($data);
        AdminActivityLogger::log('mobile.promo.updated', 'تحديث حالة كود خصم التطبيق.', $promoCode, $data, request: $request);

        return back()->with('success', 'تم تحديث كود الخصم.');
    }

    public function updatePrivacyRequest(Request $request, PrivacyRequest $privacyRequest): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'rejected'])]]);
        abort_if(in_array($privacyRequest->status, ['completed', 'cancelled'], true), 422, 'This request is already closed.');
        $privacyRequest->update($data);
        AdminActivityLogger::log('mobile.privacy_request.updated', 'تحديث سير طلب خصوصية.', $privacyRequest, [
            'status' => $data['status'], 'request_type' => $privacyRequest->request_type,
        ], request: $request);

        return back()->with('success', 'تم تحديث حالة طلب الخصوصية.');
    }

    private function promoData(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'alpha_dash:ascii', 'max:40', 'unique:mobile_promo_codes,code'],
            'name' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'minimum_subtotal' => ['nullable', 'numeric', 'min:0'],
            'maximum_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
    }

    private function normalizedPromo(array $data): array
    {
        $normalized = Arr::except($data, ['minimum_subtotal', 'maximum_discount']);

        return [
            ...$normalized,
            'code' => mb_strtoupper($data['code']),
            'discount_value' => $data['discount_type'] === 'percent'
                ? (int) round(((float) $data['discount_value']) * 100)
                : (int) round(((float) $data['discount_value']) * 100),
            'minimum_subtotal_cents' => (int) round(((float) ($data['minimum_subtotal'] ?? 0)) * 100),
            'maximum_discount_cents' => isset($data['maximum_discount']) ? (int) round(((float) $data['maximum_discount']) * 100) : null,
            'is_active' => true,
        ];
    }
}

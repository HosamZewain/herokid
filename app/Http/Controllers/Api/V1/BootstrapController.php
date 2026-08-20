<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use Illuminate\Http\JsonResponse;

class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $countries = DeliveryCountry::query()
            ->where('active', true)
            ->with(['activeGovernorates:id,delivery_country_id,name,delivery_fee'])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'delivery_fee'])
            ->map(fn (DeliveryCountry $country): array => [
                'id' => $country->id,
                'name' => $country->name,
                'code' => $country->code,
                'currency' => 'EGP',
                'delivery_fee' => $country->delivery_fee,
                'governorates' => $country->activeGovernorates->map(fn ($governorate): array => [
                    'id' => $governorate->id,
                    'name' => $governorate->name,
                    'delivery_fee' => (float) ($governorate->delivery_fee ?? $country->delivery_fee),
                ])->values(),
            ]);

        return response()->json([
            'data' => [
                'app' => [
                    'minimum_supported_version' => (string) setting('mobile_minimum_supported_version', '1.0.0'),
                    'latest_version' => (string) setting('mobile_latest_version', '1.0.0'),
                    'force_update' => (bool) setting('mobile_force_update', false),
                    'maintenance_mode' => (bool) setting('mobile_maintenance_mode', false),
                ],
                'locales' => ['ar', 'en'],
                'default_locale' => 'ar',
                'support' => [
                    'whatsapp' => setting('whatsapp_number'),
                    'phone' => setting('phone'),
                    'email' => setting('email'),
                ],
                'features' => [
                    'child_identity' => (bool) setting('child_identity_enabled', true),
                    'cash_on_delivery' => (bool) setting('cash_on_delivery_enabled', true),
                    'favorites' => true,
                    'saved_drafts' => true,
                ],
                'home' => [
                    'banner' => [
                        'title_ar' => (string) setting('mobile_home_banner_title_ar', 'طفلك هو بطل الحكاية'),
                        'title_en' => (string) setting('mobile_home_banner_title_en', 'Your child is the hero'),
                        'subtitle_ar' => (string) setting('mobile_home_banner_subtitle_ar', ''),
                        'subtitle_en' => (string) setting('mobile_home_banner_subtitle_en', ''),
                        'image_url' => setting('mobile_home_banner_image_url'),
                        'deep_link' => (string) setting('mobile_home_banner_deep_link', '/catalog'),
                    ],
                ],
                'delivery_countries' => $countries,
            ],
        ]);
    }
}

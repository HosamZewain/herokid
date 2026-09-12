<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Bosta\BostaAddressCatalogService;
use App\Services\Bosta\BostaCheckoutAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CheckoutAddressController extends Controller
{
    public function districts(
        Request $request,
        BostaCheckoutAddressService $checkoutAddresses,
        BostaAddressCatalogService $catalog,
    ): JsonResponse {
        abort_unless($checkoutAddresses->configured(), 404);

        $validated = $request->validate([
            'city_id' => ['required', 'string', 'max:100'],
        ]);
        try {
            $city = $catalog->findCityById($validated['city_id']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر تحميل المناطق الآن. حاول مرة أخرى بعد قليل.',
            ], 503);
        }
        abort_unless($city, 422, 'المحافظة المختارة غير متاحة.');

        try {
            $districts = $catalog->districts($validated['city_id']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر تحميل المناطق الآن. حاول مرة أخرى بعد قليل.',
            ], 503);
        }

        return response()->json([
            'districts' => collect($districts)
                ->map(fn (array $district): array => [
                    'id' => $district['id'],
                    'name' => $district['name'],
                    'other_name' => $district['other_name'],
                    'label' => $district['label'],
                    'zone_id' => $district['zone_id'],
                    'zone_name' => $district['zone_name'],
                    'zone_other_name' => $district['zone_other_name'],
                ])
                ->values(),
        ]);
    }
}

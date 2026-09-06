<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\Order;
use App\Services\Bosta\BostaAddressCatalogService;
use App\Services\Bosta\BostaClient;
use App\Services\Bosta\BostaPickupService;
use App\Services\Bosta\BostaPickupSyncService;
use App\Services\Bosta\BostaShipmentEligibilityService;
use App\Services\Bosta\BostaShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaController extends Controller
{
    public function districts(Request $request, BostaAddressCatalogService $catalog): JsonResponse
    {
        $values = $request->validate(['city_id' => ['required', 'string', 'max:100']]);
        abort_unless($catalog->findCityById($values['city_id']), 422, 'محافظة Bosta غير صالحة.');

        return response()->json(['districts' => $catalog->districts($values['city_id'])]);
    }

    public function index(
        Request $request,
        BostaShipmentEligibilityService $eligibility,
        BostaPickupSyncService $pickupSync,
    ) {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['active', 'finished'])],
            'q' => ['nullable', 'string', 'max:120'],
            'shipment_status' => ['nullable', 'string', 'max:100'],
            'governorate' => ['nullable', 'string', 'max:120'],
            'pickup_state' => ['nullable', Rule::in(['all', 'awaiting', 'scheduled', 'provider_progress'])],
            'per_page' => ['nullable', Rule::in(['50', '100'])],
            'refresh_pickups' => ['nullable', 'boolean'],
        ]);

        $pickupSyncWarning = null;
        $pickupSyncResult = null;
        if ($configured = config('bosta.enabled') && filled(config('bosta.api_key')) && filled(config('bosta.business_location_id'))) {
            try {
                $pickupSyncResult = $pickupSync->syncIfDue($request->boolean('refresh_pickups'));
            } catch (Throwable $exception) {
                report($exception);
                $pickupSyncWarning = 'تعذر تحديث Pickups من Bosta الآن. ما زالت حالات Webhook تمنع تكرار الاستلام للشحنات التي تحركت لدى Bosta.';
            }
        }

        $tab = $filters['tab'] ?? 'active';
        $perPage = (int) ($filters['per_page'] ?? 50);
        $baseShipments = BostaShipment::query()
            ->where('creation_status', 'created')
            ->whereNotNull('tracking_number');

        $activeCount = (clone $baseShipments)
            ->where(fn ($query) => $query->whereNull('shipping_status')->orWhere('shipping_status', '!=', 'delivered'))
            ->count();
        $finishedCount = (clone $baseShipments)->where('shipping_status', 'delivered')->count();

        $shipmentStatuses = (clone $baseShipments)
            ->whereNotNull('shipping_status')
            ->distinct()
            ->orderBy('shipping_status')
            ->pluck('shipping_status');

        $governorates = Order::query()
            ->whereIn('checkout_group_key', (clone $baseShipments)->select('checkout_group_key'))
            ->get(['delivery_details'])
            ->map(fn (Order $order): string => trim((string) data_get($order->delivery_details, 'governorate')))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $shipments = (clone $baseShipments)
            ->with(['order.checkoutReference', 'createdBy:id,name', 'activePickups:id,created_by_user_id,status'])
            ->when(
                $tab === 'finished',
                fn ($query) => $query->where('shipping_status', 'delivered'),
                fn ($query) => $query->where(fn ($status) => $status
                    ->whereNull('shipping_status')
                    ->orWhere('shipping_status', '!=', 'delivered')),
            )
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters): void {
                $search = trim((string) $filters['q']);
                $query->where(function ($query) use ($search): void {
                    $query->where('business_reference', 'like', "%{$search}%")
                        ->orWhere('tracking_number', 'like', "%{$search}%")
                        ->orWhereHas('order', function ($order) use ($search): void {
                            $order->where('parent_name', 'like', "%{$search}%")
                                ->orWhere('delivery_details->phone', 'like', "%{$search}%")
                                ->orWhere('delivery_details->mobile', 'like', "%{$search}%")
                                ->orWhere('delivery_details->governorate', 'like', "%{$search}%");
                        });
                });
            })
            ->when(filled($filters['shipment_status'] ?? null), fn ($query) => $query->where('shipping_status', $filters['shipment_status']))
            ->when(filled($filters['governorate'] ?? null), fn ($query) => $query->whereHas(
                'order',
                fn ($order) => $order->where('delivery_details->governorate', $filters['governorate']),
            ))
            ->when(($filters['pickup_state'] ?? 'all') === 'awaiting', fn ($query) => $query->awaitingPickup())
            ->when(($filters['pickup_state'] ?? 'all') === 'scheduled', fn ($query) => $query->whereHas('activePickups'))
            ->when(($filters['pickup_state'] ?? 'all') === 'provider_progress', fn ($query) => $query->withProviderPickupEvidence())
            ->latest('last_event_at')
            ->latest('id')
            ->paginate($perPage)
            ->appends($request->except('page', 'refresh_pickups'));

        return view('admin.bosta.index', [
            'configured' => $configured ?? false,
            'shipments' => $shipments,
            'shipmentStatuses' => $shipmentStatuses,
            'governorates' => $governorates,
            'filters' => $filters,
            'tab' => $tab,
            'activeCount' => $activeCount,
            'finishedCount' => $finishedCount,
            'pickupSyncWarning' => $pickupSyncWarning,
            'pickupSyncResult' => $pickupSyncResult,
            'pickupSyncedAt' => Cache::get(BostaPickupSyncService::LAST_SYNC_CACHE_KEY),
            'pickups' => BostaPickup::query()->with('createdBy:id,name')->latest()->take(10)->get(),
            'eligibleOrders' => $eligibility->eligibleRepresentatives(),
        ]);
    }

    public function createShipment(Request $request, int $representative, BostaShipmentService $service)
    {
        $overrides = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'bosta_city_id' => ['nullable', 'string', 'max:100', 'required_with:bosta_district_id'],
            'bosta_district_id' => ['nullable', 'string', 'max:100', 'required_with:bosta_city_id'],
            'governorate' => ['nullable', 'string', 'max:120'],
            'district_name' => ['nullable', 'string', 'max:160'],
            'first_line' => ['nullable', 'string', 'max:500'],
            'second_line' => ['nullable', 'string', 'max:500'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
        ]);

        try {
            $shipment = $service->create(Order::query()->findOrFail($representative), $request->user(), $request, $overrides);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['order' => 'تعذر إنشاء الشحنة في Bosta. تم حفظ المحاولة ويمكن إعادة المحاولة لاحقًا.']);
        }

        return back()->with('success', 'تم إنشاء الشحنة في Bosta. رقم التتبع: '.($shipment->tracking_number ?: 'قيد التجهيز'));
    }

    public function createPickup(Request $request, BostaPickupService $service)
    {
        $values = $request->validate([
            'shipments' => ['required', 'array', 'min:1'],
            'shipments.*' => ['integer', 'distinct', 'exists:bosta_shipments,id'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $service->create($values['shipments'], $values, $request->user(), $request);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['shipments' => 'تعذر إنشاء طلب الاستلام في Bosta. لم يتم تسجيل Pickup غير مكتمل.']);
        }

        return back()->with('success', 'تم إنشاء طلب الاستلام في Bosta وربطه بالشحنات المختارة.');
    }

    public function awb(Request $request, BostaClient $client)
    {
        $values = $request->validate([
            'shipments' => ['required', 'array', 'min:1'],
            'shipments.*' => ['integer', 'distinct', 'exists:bosta_shipments,id'],
            'awb_type' => ['nullable', Rule::in(['A4', 'A6'])],
        ]);
        $tracking = BostaShipment::query()->whereIn('id', $values['shipments'])->pluck('tracking_number')->filter()->values()->all();
        abort_if($tracking === [], 422, 'لا توجد أرقام تتبع صالحة.');
        $response = $client->createAwb($tracking, 'ar', $values['awb_type'] ?? 'A6');
        $content = data_get($response, 'data.file')
            ?: data_get($response, 'data.base64')
            ?: (is_string(data_get($response, 'data')) ? data_get($response, 'data') : null)
            ?: data_get($response, 'file')
            ?: data_get($response, 'base64');
        abort_unless(is_string($content) && base64_decode($content, true) !== false, 502, 'لم ترجع Bosta ملف AWB صالحًا.');

        return response(base64_decode($content), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="bosta-awb.pdf"']);
    }
}

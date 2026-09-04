<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\Order;
use App\Services\Bosta\BostaClient;
use App\Services\Bosta\BostaPickupService;
use App\Services\Bosta\BostaShipmentEligibilityService;
use App\Services\Bosta\BostaShipmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class BostaController extends Controller
{
    public function index(BostaShipmentEligibilityService $eligibility)
    {
        return view('admin.bosta.index', [
            'configured' => config('bosta.enabled') && filled(config('bosta.api_key')) && filled(config('bosta.business_location_id')),
            'shipments' => BostaShipment::query()
                ->with(['order.checkoutReference', 'createdBy:id,name'])
                ->where('creation_status', 'created')
                ->whereNotNull('tracking_number')
                ->whereDoesntHave('pickups')
                ->latest()
                ->paginate(25),
            'pickups' => BostaPickup::query()->with('createdBy:id,name')->latest()->take(10)->get(),
            'eligibleOrders' => $eligibility->eligibleRepresentatives(),
        ]);
    }

    public function createShipment(Request $request, int $representative, BostaShipmentService $service)
    {
        $overrides = $request->validate([
            'receiver_name' => ['nullable', 'string', 'max:120'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
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
        $values = $request->validate(['shipments' => ['required', 'array', 'min:1'], 'shipments.*' => ['integer', 'distinct', 'exists:bosta_shipments,id']]);
        $tracking = BostaShipment::query()->whereIn('id', $values['shipments'])->pluck('tracking_number')->filter()->values()->all();
        abort_if($tracking === [], 422, 'لا توجد أرقام تتبع صالحة.');
        $response = $client->createAwb($tracking);
        $content = data_get($response, 'data.file')
            ?: data_get($response, 'data.base64')
            ?: (is_string(data_get($response, 'data')) ? data_get($response, 'data') : null)
            ?: data_get($response, 'file')
            ?: data_get($response, 'base64');
        abort_unless(is_string($content) && base64_decode($content, true) !== false, 502, 'لم ترجع Bosta ملف AWB صالحًا.');

        return response(base64_decode($content), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="bosta-awb.pdf"']);
    }
}

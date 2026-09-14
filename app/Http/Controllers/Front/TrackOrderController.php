<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Services\Orders\CustomerOrderSelfService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TrackOrderController extends Controller
{
    public function index()
    {
        return view('front.track.index');
    }

    public function track(Request $request, CustomerOrderSelfService $orders)
    {
        $validated = $request->validate([
            'order_number' => 'required|string',
            'phone' => 'required|string|max:20',
        ]);

        $group = $orders->findByCredentials($validated['order_number'], $validated['phone']);

        if (! $group) {
            return back()->with('error', 'البيانات غير صحيحة. يرجى التأكد من رقم الطلب ورقم الموبايل.');
        }

        $orders->authorize($request, $group);

        return view('front.track.show', compact('group'));
    }

    public function show(Request $request, string $reference, CustomerOrderSelfService $orders)
    {
        $group = $orders->authorizedGroup($request, $reference);

        return view('front.track.show', compact('group'));
    }

    public function activeOrders(Request $request, CustomerOrderSelfService $orders): JsonResponse
    {
        $validated = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        return response()->json([
            'orders' => $orders->activeForPhone(Phone::normalize($validated['phone'])),
        ]);
    }

    public function edit(Request $request, string $reference, CustomerOrderSelfService $orders)
    {
        $group = $orders->authorizedGroup($request, $reference);
        abort_unless($group['can_edit'] || $group['can_update_parent_notes'], 403);

        return view('front.track.edit', [
            'group' => $group,
            'deliveryCountries' => DeliveryCountry::query()
                ->where('active', true)
                ->with(['activeGovernorates' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(Request $request, string $reference, CustomerOrderSelfService $orders)
    {
        $group = $orders->authorizedGroup($request, $reference);
        $request->merge(['phone' => Phone::normalize($request->input('phone'))]);
        $rules = $group['can_edit'] ? [
            'parent_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'delivery_country_id' => ['required', Rule::exists('delivery_countries', 'id')->where('active', true)],
            'delivery_governorate_id' => ['required', Rule::exists('delivery_governorates', 'id')->where('active', true)],
            'city' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'address_details' => ['required', 'string', 'max:1000'],
            'children' => ['sometimes', 'array'],
            'children.*.child_name' => ['required', 'string', 'max:255'],
            'children.*.child_age' => ['nullable', 'integer', 'min:2', 'max:16'],
            'children.*.child_gender' => ['nullable', Rule::in(['boy', 'girl'])],
            'children.*.parent_notes' => ['nullable', 'string', 'max:2000'],
        ] : [
            'children' => ['required', 'array'],
            'children.*.parent_notes' => ['nullable', 'string', 'max:2000'],
        ];
        $validated = $request->validate($rules);

        $updated = $orders->update($request, $group, $validated);

        return redirect()
            ->route('track.show', $updated['short_reference'])
            ->with('success', 'تم حفظ تعديلات الطلب بنجاح.');
    }

    public function cancel(Request $request, string $reference, CustomerOrderSelfService $orders)
    {
        $validated = $request->validate(['confirm_cancel' => ['accepted']]);
        $group = $orders->authorizedGroup($request, $reference);
        $cancelled = $orders->cancel($request, $group);

        return redirect()
            ->route('track.show', $cancelled['short_reference'])
            ->with('success', 'تم إلغاء الطلب بناءً على طلبك.');
    }
}

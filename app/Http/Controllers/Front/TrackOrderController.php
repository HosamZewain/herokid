<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Phone;
use Illuminate\Http\Request;

class TrackOrderController extends Controller
{
    public function index()
    {
        return view('front.track.index');
    }

    public function track(Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string',
            'phone' => 'required|string|max:20',
        ]);

        $reference = strtoupper(trim($validated['order_number']));
        $phone = Phone::forWhatsApp($validated['phone']);

        $order = Order::with(['checkoutReference', 'story', 'statusLogs'])
            ->where(function ($query) use ($reference): void {
                $query
                    ->where('order_number', $reference)
                    ->orWhereHas('checkoutReference', fn ($referenceQuery) => $referenceQuery
                        ->where('short_reference', $reference));
            })
            ->whereIn('delivery_details->phone', Phone::equivalentValues($validated['phone']))
            ->orderBy('id')
            ->first();

        if (! $order || Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) !== $phone) {
            return back()->with('error', 'البيانات غير صحيحة. يرجى التأكد من رقم الطلب ورقم الموبايل.');
        }

        return view('front.track.show', compact('order'));
    }
}

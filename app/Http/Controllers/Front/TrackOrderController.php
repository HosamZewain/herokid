<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TrackOrderController extends Controller
{
    public function index()
    {
        return view('front.track.index');
    }

    public function track(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string',
            'phone' => 'required|string|max:20',
        ]);

        $order = \App\Models\Order::with(['story', 'statusLogs'])
            ->where('order_number', $validated['order_number'])
            ->where('delivery_details->phone', $validated['phone'])
            ->first();

        if (!$order) {
            return back()->with('error', 'البيانات غير صحيحة. يرجى التأكد من رقم الطلب ورقم الموبايل.');
        }

        return view('front.track.show', compact('order'));
    }
}

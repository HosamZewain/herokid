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
            'email' => 'required|email',
        ]);

        $order = \App\Models\Order::with(['story', 'statusLogs'])
            ->where('order_number', $validated['order_number'])
            ->whereJsonContains('delivery_details->email', $validated['email'])
            ->first();

        if (!$order) {
            // Unregistered users or security fallback
            $order = \App\Models\Order::with(['story', 'statusLogs'])
                ->where('order_number', $validated['order_number'])
                ->whereHas('user', function($q) use ($validated) {
                    $q->where('email', $validated['email']);
                })
                ->first();
        }

        if (!$order) {
            return back()->with('error', 'البيانات غير صحيحة. يرجى التأكد من رقم الطلب والبريد الإلكتروني.');
        }

        return view('front.track.show', compact('order'));
    }
}

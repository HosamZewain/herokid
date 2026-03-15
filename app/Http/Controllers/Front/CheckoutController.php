<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(\Illuminate\Http\Request $request, \App\Models\Story $story)
    {
        $validated = $request->validate([
            'parent_name'     => 'required|string|max:255',
            'child_name'      => 'required|string|max:255',
            'child_age'       => 'required|integer|min:0|max:18',
            'child_gender'    => 'required|in:boy,girl',
            'gift_note'       => 'nullable|string|max:500',
            'interests'       => 'nullable|string|max:500',
            'parent_notes'    => 'nullable|string|max:1000',
            'email'           => 'required|email|max:255',
            'phone'           => 'required|string|max:20',
            'governorate'     => 'required|string|max:255',
            'city'            => 'required|string|max:255',
            'address'         => 'required|string|max:1000',
            'privacy_consent' => 'required|accepted',
            'photos'          => 'required|array|min:1|max:5',
            'photos.*'        => 'image|mimes:jpeg,png,jpg|max:5120', // Max 5MB per photo
        ]);

        $photoPaths = [];
        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                // Store in private folder (not public) to prevent direct URL access
                $path = $photo->store('orders/photos/' . date('Y-m'));
                $photoPaths[] = $path;
            }
        }

        // Generate unique order number (e.g., HK-2026-AB3X7Y)
        $orderNumber = 'HK-' . date('Y') . '-' . strtoupper(\Illuminate\Support\Str::random(6));

        $order = \App\Models\Order::create([
            'order_number'    => $orderNumber,
            'user_id'         => auth()->id(), // null if guest
            'parent_name'     => $validated['parent_name'],
            'story_id'        => $story->id,
            'child_name'      => $validated['child_name'],
            'child_age'       => $validated['child_age'],
            'child_gender'    => $validated['child_gender'],
            'language'        => $story->language,
            'lesson'          => $story->lesson_value,
            'interests'       => $validated['interests'] ?? null,
            'gift_note'       => $validated['gift_note'] ?? null,
            'notes'           => null, // internal admin notes
            'parent_notes'    => $validated['parent_notes'] ?? null,
            'delivery_details' => [
                'email'       => $validated['email'],
                'phone'       => $validated['phone'],
                'governorate' => $validated['governorate'],
                'city'        => $validated['city'],
                'address'     => $validated['address'],
            ],
            'uploaded_photos' => $photoPaths,
            'status'          => 'new',
        ]);

        // Log initial status
        $order->statusLogs()->create([
            'status' => 'new',
            'notes'  => 'تم إنشاء الطلب بنجاح وسيتم مراجعته قريباً.',
        ]);

        return redirect()->route('checkout.success', $order);
    }

    public function success(\App\Models\Order $order)
    {
        return view('front.checkout.success', compact('order'));
    }
}

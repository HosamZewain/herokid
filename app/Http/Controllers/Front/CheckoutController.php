<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'governorate' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'address' => 'required|string|max:1000',
        ]);

        $cart = session('cart.items', []);

        if ($cart === []) {
            return redirect()->route('cart.index')->with('error', 'السلة فارغة. أضف قصة واحدة على الأقل قبل تأكيد الطلب.');
        }

        $stories = Story::whereIn('id', collect($cart)->pluck('story_id')->filter()->all())->get()->keyBy('id');
        $subtotal = collect($cart)->sum(function (array $item) use ($stories): float {
            return (float) ($stories->get($item['story_id'] ?? null)?->price ?? $item['story_price'] ?? 0);
        });
        $deliveryFee = $this->deliveryFee();
        $checkoutGroup = 'CHK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        $orderIds = [];

        DB::transaction(function () use ($cart, $stories, $validated, $subtotal, $deliveryFee, $checkoutGroup, &$orderIds): void {
            $itemCount = count($cart);

            foreach (array_values($cart) as $index => $item) {
                $story = $stories->get($item['story_id'] ?? null);

                if (! $story) {
                    continue;
                }

                $order = Order::create([
                    'order_number' => $this->newOrderNumber(),
                    'user_id' => auth()->id(),
                    'parent_name' => $validated['parent_name'],
                    'story_id' => $story->id,
                    'child_name' => $item['child_name'],
                    'child_age' => $item['child_age'],
                    'child_gender' => $item['child_gender'],
                    'language' => $story->language,
                    'lesson' => $story->lesson_value,
                    'interests' => $item['interests'] ?? null,
                    'gift_note' => $item['gift_note'] ?? null,
                    'notes' => null,
                    'parent_notes' => $item['parent_notes'] ?? null,
                    'delivery_details' => [
                        'email' => $validated['email'],
                        'phone' => $validated['phone'],
                        'governorate' => $validated['governorate'],
                        'city' => $validated['city'],
                        'address' => $validated['address'],
                        'checkout_group' => $checkoutGroup,
                        'cart_item_index' => $index + 1,
                        'cart_items_count' => $itemCount,
                        'item_price' => (float) $story->price,
                        'subtotal' => $subtotal,
                        'delivery_fee' => $deliveryFee,
                        'total' => $subtotal + $deliveryFee,
                    ],
                    'uploaded_photos' => $item['uploaded_photos'] ?? [],
                    'status' => 'new',
                ]);

                $order->statusLogs()->create([
                    'status' => 'new',
                    'notes' => 'تم إنشاء الطلب بنجاح وسيتم مراجعته قريباً.',
                ]);

                $orderIds[] = $order->id;
            }
        });

        if ($orderIds === []) {
            return redirect()->route('cart.index')->with('error', 'تعذر إنشاء الطلب لأن بعض القصص لم تعد متاحة.');
        }

        session()->forget('cart.items');
        session(['checkout.last_order_ids' => $orderIds]);

        return redirect()->route('checkout.success');
    }

    public function success()
    {
        $orderIds = session('checkout.last_order_ids', []);
        $orders = Order::with('story')->whereIn('id', $orderIds)->get();

        if ($orders->isEmpty()) {
            return redirect()->route('stories.index');
        }

        return view('front.checkout.success', [
            'orders' => $orders,
            'order' => $orders->first(),
        ]);
    }

    private function newOrderNumber(): string
    {
        do {
            $orderNumber = 'HK-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function deliveryFee(): float
    {
        $settings = Cache::remember('site_settings', 3600, fn () => Setting::all()->pluck('value', 'key')->toArray());

        return max(0, (float) ($settings['delivery_fee'] ?? 0));
    }
}

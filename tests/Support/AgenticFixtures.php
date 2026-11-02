<?php

namespace Tests\Support;

use App\Models\AiCheckoutState;
use App\Models\Order;
use App\Models\Product;
use App\Services\Agentic\ContextObserver;

trait AgenticFixtures
{
    private function observedCheckout(string $key, int $units = 1): AiCheckoutState
    {
        $product = Product::firstOrCreate(['slug' => 'personalized-child-adventure-hdf-puzzle'], [
            'name_ar' => 'Synthetic puzzle', 'price_cents' => 100, 'is_active' => true, 'production_prompt_template' => 'Draw {{child_full_name}}']);
        for ($i = 0; $i < $units; $i++) {
            $order = Order::create(['checkout_group_key' => $key, 'order_number' => 'SYN-'.bin2hex(random_bytes(5)),
                'parent_name' => 'Synthetic', 'child_name' => 'Synthetic child '.$i, 'child_age' => 7, 'child_gender' => 'girl',
                'status' => 'new_confirmed', 'uploaded_photos' => ['synthetic/photo.png']]);
            $order->items()->create(['product_id' => $product->id, 'item_type' => 'product', 'title' => 'Synthetic', 'quantity' => 1,
                'unit_price_cents' => 100, 'total_price_cents' => 100]);
        }

        return app(ContextObserver::class)->observe($key);
    }
}

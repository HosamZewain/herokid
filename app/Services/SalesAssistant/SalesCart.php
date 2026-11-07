<?php

namespace App\Services\SalesAssistant;

use App\Services\Cart\PackageCartExpander;
use Illuminate\Http\Request;

class SalesCart
{
    public function summary(Request $request): array
    {
        $cart = app(PackageCartExpander::class)->expand($request->session()->get('cart.items', []));

        $catalog = $cart ? app(SalesCatalog::class)->all() : [];

        return ['line_count' => count($cart),
            'checkout' => ['cart_is_submitted_order' => false, 'delivery_review_required' => count($cart) > 0, 'confirmation_required' => true],
            'private_intake_fields_present' => array_keys(app(SalesIntake::class)->read($request)), 'items' => array_values(array_map(function (array $item) use ($catalog) {
                $story = ($item['item_type'] ?? 'story') === 'story';

                $key = ($story ? 'story:' : 'product:').(int) ($item[$story ? 'story_id' : 'product_id'] ?? 0);

                return ['title' => $catalog[$key]['title'] ?? 'عنصر من السلة', 'key' => ($story ? 'story:' : 'product:').(int) ($item[$story ? 'story_id' : 'product_id'] ?? 0),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'has_child_data' => ! empty($item['child_name']), 'has_photos' => ! empty($item['uploaded_photos'])];
            }, array_slice($cart, 0, 20)))];
    }
}

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

        return ['line_count' => count($cart), 'items' => array_values(array_map(function (array $item) use ($catalog) {
            $story = ($item['item_type'] ?? 'story') === 'story';

            $key = ($story ? 'story:' : 'product:').(int) ($item[$story ? 'story_id' : 'product_id'] ?? 0);

            return ['title' => $catalog[$key]['title'] ?? 'عنصر من السلة', 'key' => ($story ? 'story:' : 'product:').(int) ($item[$story ? 'story_id' : 'product_id'] ?? 0),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'has_child_data' => ! empty($item['child_name']), 'has_photos' => ! empty($item['uploaded_photos'])];
        }, array_slice($cart, 0, 20)))];
    }
}

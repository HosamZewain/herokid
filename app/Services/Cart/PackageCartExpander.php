<?php

namespace App\Services\Cart;

class PackageCartExpander
{
    public function expand(array $cart): array
    {
        $expanded = [];

        foreach ($cart as $key => $item) {
            if (($item['item_type'] ?? 'story') !== 'package') {
                $expanded[$key] = $item;

                continue;
            }

            foreach ($item['package_stories'] ?? [] as $story) {
                $expanded[$story['key']] = $story;
            }

            foreach ($item['package_products'] ?? [] as $product) {
                $expanded[$product['key']] = $product;
            }
        }

        return $expanded;
    }
}

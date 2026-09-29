<?php

namespace App\Services\Cart;

use App\Models\Product;

class WebsiteCartPricingService
{
    /**
     * Refresh direct product prices from the catalog without changing story or package snapshots.
     *
     * @param  array<string, array<string, mixed>>  $cart
     * @return array<string, array<string, mixed>>
     */
    public function refresh(array $cart): array
    {
        $productIds = collect($cart)
            ->filter(fn (array $item): bool => $this->isDirectProduct($item))
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->all();

        if ($productIds === []) {
            return $cart;
        }

        $products = Product::query()
            ->with('variants')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($cart as $key => $item) {
            if (! $this->isDirectProduct($item)) {
                continue;
            }

            $product = $products->get($item['product_id'] ?? null);
            $pricingSnapshot = $item['pricing_snapshot'] ?? null;
            if (! $product
                || ! is_array($pricingSnapshot)
                || ($pricingSnapshot['source'] ?? null) !== 'scheduled_product_sale'
                || (int) ($pricingSnapshot['version'] ?? 0) !== 1) {
                continue;
            }

            $variant = ! empty($item['variant_id'])
                ? $product->variants->firstWhere('id', (int) $item['variant_id'])
                : null;
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $saleApplied = $product->hasActiveSaleForVariant($variant);
            $saleStateChanged = (bool) ($pricingSnapshot['sale_applied'] ?? false) !== $saleApplied;
            $activeSalePriceChanged = $saleApplied
                && (int) ($pricingSnapshot['sale_price_cents'] ?? -1) !== $product->salePriceCents($variant);

            if (! $saleStateChanged && ! $activeSalePriceChanged) {
                continue;
            }

            $unitPriceCents = $product->effectivePriceCents($variant);

            $cart[$key]['unit_price_cents'] = $unitPriceCents;
            $cart[$key]['unit_price'] = $unitPriceCents / 100;
            $cart[$key]['line_total_cents'] = $unitPriceCents * $quantity;

            if (is_array($cart[$key]['variant_snapshot'] ?? null)) {
                $cart[$key]['variant_snapshot']['effective_price_cents'] = $unitPriceCents;
            }

            $cart[$key]['pricing_snapshot'] = [
                'version' => 1,
                'source' => 'scheduled_product_sale',
                'sale_applied' => $saleApplied,
                'regular_price_cents' => $product->regularPriceCents($variant),
                'sale_price_cents' => $product->salePriceCents($variant),
                'sale_starts_at' => $product->sale_starts_at?->toIso8601String(),
                'sale_ends_at' => $product->sale_ends_at?->toIso8601String(),
            ];
        }

        return $cart;
    }

    /** @param array<string, mixed> $item */
    private function isDirectProduct(array $item): bool
    {
        return in_array($item['item_type'] ?? null, ['product', 'product_add_on'], true)
            && empty($item['package_snapshot']);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\SalesAssistant\SalesSessions;
use App\Services\SalesAssistant\SalesSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SalesEmbed
{
    public const ROUTES = ['sales.item', 'sales.cart-edit', 'sales.cart-update', 'cart.index', 'cart.store', 'cart.products.store', 'cart.packages.store', 'cart.destroy',
        'cart.promo-code.store', 'cart.promo-code.destroy', 'checkout.store', 'checkout.success', 'stories.show', 'shop.product.show', 'shop.package.show'];

    public function handle(Request $request, Closure $next)
    {
        $embedded = $request->boolean('_sales_assistant_embed') && $request->routeIs(self::ROUTES)
            && app(SalesSettings::class)->enabled() && app(SalesSessions::class)->current($request);
        $request->attributes->set('sales_assistant_embed', (bool) $embedded);
        if ($embedded && Str::isUuid((string) $request->query('_sales_entry', ''))) {
            $request->attributes->set('sales_intake_key', $request->query('_sales_entry'));
        }
        $cartBefore = $embedded ? array_keys($request->session()->get('cart.items', [])) : [];
        $response = $next($request);
        if ($embedded && ($response->isSuccessful() || $response->isRedirection())) {
            if ($request->routeIs('cart.store', 'cart.products.store', 'cart.packages.store')) {
                $conversation = app(SalesSessions::class)->current($request);
                foreach (array_diff_key($request->session()->get('cart.items', []), array_flip($cartBefore)) as $item) {
                    $type = $item['item_type'] ?? 'story';
                    $key = $type === 'package' ? 'package:'.$item['pricing_package_id'] : ($type === 'story' ? 'story:'.$item['story_id'] : 'product:'.$item['product_id']);
                    if ($conversation) {
                        app(SalesSessions::class)->event($conversation, 'cart_added', $key);
                    }
                }
            }
        }
        if ($embedded) {
            $response->headers->set('Cache-Control', 'private, no-store');
            if ($response->isRedirection() && ($location = $response->headers->get('Location'))) {
                $url = parse_url($location);
                // Carry display context only to same-origin native commerce routes.
                if ((! isset($url['host']) || $url['host'] === $request->getHost()) && preg_match('~^/(cart|checkout|stories|shop)(/|$)~', $url['path'] ?? '')) {
                    $response->headers->set('Location', $location.(str_contains($location, '?') ? '&' : '?').'_sales_assistant_embed=1');
                }
            }
        }

        return $response;
    }
}

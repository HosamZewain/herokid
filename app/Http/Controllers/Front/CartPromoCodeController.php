<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Cart\WebsitePromoCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartPromoCodeController extends Controller
{
    public function store(Request $request, WebsitePromoCodeService $promoCodes): RedirectResponse
    {
        $validated = $request->validate([
            'promo_code' => ['required', 'string', 'max:40'],
        ], [
            'promo_code.required' => 'اكتب كود الخصم أولًا.',
        ]);

        $subtotalCents = $this->subtotalCents($request->session()->get('cart.items', []));
        if ($subtotalCents <= 0) {
            return back()->withErrors(['promo_code' => 'السلة فارغة.']);
        }

        $quote = $promoCodes->apply($request, $validated['promo_code'], $subtotalCents);

        return back()->with('success', 'تم تطبيق كود الخصم «'.$quote['promo']->code.'».');
    }

    public function destroy(Request $request, WebsitePromoCodeService $promoCodes): RedirectResponse
    {
        $promoCodes->remove($request);

        return back()->with('success', 'تمت إزالة كود الخصم.');
    }

    /** @param array<string, array<string, mixed>> $cart */
    private function subtotalCents(array $cart): int
    {
        return (int) collect($cart)->sum(function (array $item): int {
            if (($item['item_type'] ?? 'story') === 'story') {
                return (int) round(((float) ($item['story_price'] ?? 0)) * 100);
            }

            return (int) ($item['line_total_cents'] ?? 0);
        });
    }
}

<?php

namespace App\Services\SalesAssistant;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Story;
use App\Models\TemporaryPhotoUpload;
use App\Services\Cart\FreeShippingOfferService;
use App\Services\Cart\PackageCartExpander;
use App\Services\Cart\WebsitePromoCodeService;
use App\Services\Orders\CheckoutSubmissionService;
use App\Services\Pricing\PackagePricingService;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SalesCheckout
{
    private function changed(string $message = 'تغير السعر أو التوافر أو البيانات. ارجع للسلة وراجعها قبل التأكيد.'): never
    {
        throw ValidationException::withMessages(['sales_checkout' => $message]);
    }

    /** Refresh prices only; child, photo, linked-story and package identities are preserved. */
    public function refreshCart(array $cart): array
    {
        foreach ($cart as &$item) {
            $type = $item['item_type'] ?? 'story';
            if ($type === 'package') {
                $package = PricingPackage::with(['items.product.category', 'items.variant'])->find($item['pricing_package_id'] ?? 0);
                if (! $package?->active || ! $package->show_in_store || ! $package->availableForPurchase()) {
                    $this->changed('الباقة مش متاحة دلوقتي. راجع عناصر السلة.');
                }
                $oldProducts = array_map(fn ($p) => [(int) $p['product_id'], (int) ($p['variant_id'] ?? 0), (int) $p['quantity']], $item['package_products'] ?? []);
                $newProducts = $package->items->map(fn ($p) => [(int) $p->product_id, (int) ($p->product_variant_id ?? $p->variant_id ?? 0), (int) $p->quantity])->all();
                if ($oldProducts !== $newProducts || count($item['package_stories'] ?? []) !== (int) $package->story_count) {
                    $this->changed('مكونات الباقة اتغيرت. احذف الباقة وأضفها من جديد بإعداداتها الحالية.');
                }
                $eligible = $package->availableStoriesQuery()->pluck('stories.id')->all();
                foreach ($item['package_stories'] as $selected) {
                    if (! in_array((int) $selected['story_id'], array_map('intval', $eligible), true)) {
                        $this->changed('القصة المختارة لم تعد متاحة داخل الباقة. راجع اختيار الباقة.');
                    }
                }
                $prices = [];
                foreach ($item['package_stories'] as $story) {
                    $prices[] = $this->storyPrice($story);
                }
                foreach ($package->items as $component) {
                    $prices[] = $component->product->effectivePriceCents($component->variant) * $component->quantity;
                }
                $total = (int) round($package->price * 100);
                $allocations = app(PackagePricingService::class)->allocate($total, $prices);
                $i = 0;
                foreach ($item['package_stories'] as &$story) {
                    $story['story_price'] = $allocations[$i++] / 100;
                    $story['package_snapshot']['package_price_cents'] = $total;
                } unset($story);
                foreach ($item['package_products'] as &$product) {
                    $product['line_total_cents'] = $allocations[$i++];
                    $product['unit_price_cents'] = intdiv($product['line_total_cents'], max(1, (int) $product['quantity']));
                    $product['package_snapshot']['package_price_cents'] = $total;
                } unset($product);
                $item['line_total_cents'] = $total;
            } elseif ($type === 'story') {
                $item['story_price'] = $this->storyPrice($item) / 100;
            } else {
                $product = Product::publiclyVisible()->with('variants')->find($item['product_id'] ?? 0);
                $variant = ! empty($item['variant_id']) ? $product?->variants->firstWhere('id', $item['variant_id']) : null;
                if (! $product || ($product->variants->isNotEmpty() && ! $variant?->is_active) || ! $product->hasStock((int) ($item['quantity'] ?? 1), $variant)) {
                    $this->changed('منتج أو مقاس في السلة مش متاح بالكمية المطلوبة.');
                }
                $item['unit_price_cents'] = $product->effectivePriceCents($variant);
                $item['unit_price'] = $item['unit_price_cents'] / 100;
                $item['line_total_cents'] = $item['unit_price_cents'] * max(1, (int) ($item['quantity'] ?? 1));
            }
        } unset($item);

        return $cart;
    }

    private function storyPrice(array $item): int
    {
        $story = Story::where('active', true)->find($item['story_id'] ?? 0);
        if (! $story) {
            $this->changed('قصة في السلة مش متاحة حاليًا.');
        }

        return (int) round(app(StoryPricingService::class)->effectivePrice($story) * 100);
    }

    public function checkPhotos(Request $request, array $cart): void
    {
        $paths = collect($cart)->flatMap(fn ($i) => $i['uploaded_photos'] ?? [])->unique()->values();
        $uploads = TemporaryPhotoUpload::whereIn('path', $paths)->get()->keyBy('path');
        $hash = app(TemporaryPhotoUploadService::class)->ensureSession($request)['hash'];
        foreach ($cart as $item) {
            $photos = $item['uploaded_photos'] ?? [];
            if (($item['item_type'] ?? 'story') === 'story') {
                if (empty($item['child_name']) || empty($item['child_age']) || count($photos) < config('photo_uploads.min_files', 2)) {
                    $this->changed('استكمل بيانات وصور الطفل في القصة قبل التأكيد.');
                }
            } else {
                $product = Product::find($item['product_id']);
                if ($product?->personalization_mode === 'collect_child_details' && ! ProductPersonalizationSchema::cartItemIsComplete(ProductPersonalizationSchema::forProduct($product), $item)) {
                    $this->changed('متطلبات تخصيص المنتج اتغيرت أو ناقصة. راجع بيانات الطفل وصوره.');
                }
            }
            foreach ($photos as $path) {
                if (str_starts_with($path, 'temporary-uploads/')) {
                    $upload = $uploads->get($path);
                    if (! $upload || ! hash_equals($hash, $upload->session_hash) || ! in_array($upload->status, ['uploaded', 'attached'], true) || $upload->attached_order_id || ! Storage::disk($upload->disk)->exists($path)) {
                        $this->changed('صورة ناقصة أو محذوفة أو لا تخص جلسة الشراء. ارجع لبيانات الطفل وارفعها من جديد.');
                    }
                } elseif (! str_starts_with($path, 'orders/cart/') || ! Storage::disk(config('media.private_disk', 'local'))->exists($path)) {
                    $this->changed('صورة الطفل مش متاحة. راجعها في السلة قبل التأكيد.');
                }
            }
        }
    }

    public function review(Request $request, array $cart, array $details, float $subtotal, float $baseFee, string $country, string $governorate)
    {
        $this->checkPhotos($request, app(PackageCartExpander::class)->expand($cart));
        $promo = app(WebsitePromoCodeService::class)->quote($request, (int) round($subtotal * 100));
        $shipping = app(FreeShippingOfferService::class)->quote((int) round($subtotal * 100), $promo['discount_cents'], (int) round($baseFee * 100));
        $fee = (int) round($shipping['delivery_fee'] * 100);
        $quote = Crypt::encryptString(json_encode(['session' => app(SalesSessions::class)->hash($request), 'conversation' => app(SalesSessions::class)->own($request)->id,
            'expires' => now()->addMinutes(15)->timestamp, 'fingerprint' => $this->fingerprint($cart, $details, (int) round($subtotal * 100), $promo['discount_cents'], $fee)], JSON_THROW_ON_ERROR));
        $fields = $details + ['checkout_draft_scope' => $request->input('checkout_draft_scope')];
        $fields['expected_delivery_fee'] = $fee / 100;
        $fields['checkout_submission_token'] = app(CheckoutSubmissionService::class)->token($request, $cart);
        app(SalesSessions::class)->event(app(SalesSessions::class)->own($request), 'checkout_review');

        return view('front.sales-assistant.review', ['items' => app(PackageCartExpander::class)->expand($cart), 'fields' => $fields,
            'subtotalCents' => (int) round($subtotal * 100), 'discountCents' => $promo['discount_cents'], 'feeCents' => $fee,
            'totalCents' => max(0, (int) round($subtotal * 100) - $promo['discount_cents']) + $fee, 'quote' => $quote, 'country' => $country, 'governorate' => $governorate]);
    }

    public function verify(Request $request, array $cart, array $details, int $subtotal, int $discount, int $fee): void
    {
        try {
            $quote = json_decode(Crypt::decryptString($request->input('_sales_assistant_quote', '')), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->changed();
        }
        if (($quote['expires'] ?? 0) < now()->timestamp || ($quote['conversation'] ?? '') !== app(SalesSessions::class)->own($request)->id
            || ! hash_equals(app(SalesSessions::class)->hash($request), $quote['session'] ?? '')
            || ! hash_equals($this->fingerprint($cart, $details, $subtotal, $discount, $fee), $quote['fingerprint'] ?? '')) {
            $this->changed();
        }
        $this->checkPhotos($request, app(PackageCartExpander::class)->expand($cart));
    }

    private function fingerprint(array $cart, array $details, int $subtotal, int $discount, int $fee): string
    {
        $fields = [];
        foreach (['parent_name', 'phone', 'alternate_phone', 'delivery_country_id', 'delivery_governorate_id', 'city', 'bosta_city_id', 'bosta_district_id', 'street', 'address_details', 'previous_order_action', 'previous_order_reference'] as $key) {
            $fields[$key] = trim((string) ($details[$key] ?? ''));
        }

        return hash('sha256', json_encode([$cart, $fields, $subtotal, $discount, $fee], JSON_THROW_ON_ERROR));
    }

    /** Additional attribution only; native source, marketing and payment fields stay untouched. */
    public function attribute(Request $request, string $checkoutKey, int $valueCents): void
    {
        $conversation = app(SalesSessions::class)->current($request);
        if (! $conversation || $request->session()->get('sales_assistant.assisted') !== $conversation->id) {
            return;
        }
        DB::table('sales_assistant_events')->insertOrIgnore(['conversation_id' => $conversation->id, 'visitor_hash' => $conversation->visitor_hash,
            'type' => 'order_created', 'checkout_key' => $checkoutKey, 'order_value_cents' => $valueCents, 'created_at' => now()]);
    }
}

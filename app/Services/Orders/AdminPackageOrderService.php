<?php

namespace App\Services\Orders;

use App\Models\PricingPackage;
use App\Models\Story;
use App\Services\Pricing\StoryPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPackageOrderService
{
    public function __construct(private readonly StoryPricingService $storyPricing) {}

    /** @return Collection<int, PricingPackage> */
    public function availablePackages(): Collection
    {
        return PricingPackage::query()
            ->active()
            ->purchasable()
            ->with(['items.product', 'items.variant', 'eligibleStories'])
            ->ordered()
            ->get()
            ->filter(fn (PricingPackage $package): bool => $package->availableForPurchase())
            ->values();
    }

    public function prepareRequest(Request $request): ?PricingPackage
    {
        $packageId = (int) $request->input('pricing_package_id', 0);
        if ($packageId < 1) {
            return null;
        }

        $package = PricingPackage::query()
            ->active()
            ->purchasable()
            ->with(['items.product', 'items.variant', 'eligibleStories'])
            ->find($packageId);

        if (! $package || ! $package->availableForPurchase()) {
            throw ValidationException::withMessages([
                'pricing_package_id' => 'الباقة المختارة غير متاحة حاليًا.',
            ]);
        }

        $products = (array) $request->input('products', []);
        $firstStoryIndex = collect((array) $request->input('stories', []))->keys()->first();

        foreach ($package->items as $packageItem) {
            $product = $packageItem->product;
            if (! $product) {
                throw ValidationException::withMessages([
                    'pricing_package_id' => 'أحد منتجات الباقة لم يعد متاحًا.',
                ]);
            }

            $productId = (int) $product->id;
            $input = (array) ($products[$productId] ?? []);
            $submittedQuantity = max(0, (int) ($input['quantity'] ?? 0));
            $requiredQuantity = max(1, (int) $packageItem->quantity);

            if ($packageItem->product_variant_id) {
                $submittedVariantId = (int) ($input['variant_id'] ?? 0);
                if ($submittedVariantId > 0 && $submittedVariantId !== (int) $packageItem->product_variant_id) {
                    throw ValidationException::withMessages([
                        "products.$productId.variant_id" => 'الخيار المحدد لهذا المنتج لا يطابق خيار الباقة.',
                    ]);
                }
                $input['variant_id'] = (int) $packageItem->product_variant_id;
            } elseif ((int) ($input['variant_id'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    "products.$productId.variant_id" => 'هذه الباقة تستخدم المنتج الأساسي وليس هذا الخيار.',
                ]);
            }

            $input['quantity'] = max($submittedQuantity, $requiredQuantity);
            if ($product->isPersonalizedAddon()) {
                if ($firstStoryIndex === null) {
                    throw ValidationException::withMessages([
                        'stories' => 'تحتوي الباقة على منتج مرتبط بقصة؛ أضف بيانات قصة الباقة أولًا.',
                    ]);
                }
                $input['linked_story_index'] = (int) $firstStoryIndex;
            }

            $products[$productId] = $input;
        }

        $request->merge(['products' => $products]);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function applyPackage(array $validated, ?PricingPackage $package): array
    {
        if (! $package) {
            return $validated;
        }

        $stories = collect($validated['stories'] ?? []);
        if ($stories->count() !== (int) $package->story_count) {
            throw ValidationException::withMessages([
                'stories' => 'هذه الباقة تتطلب إدخال بيانات '.(int) $package->story_count.' قصة بالضبط.',
            ]);
        }

        $allowedStoryIds = $package->availableStoriesQuery()->pluck('stories.id')->map(fn ($id): int => (int) $id);
        $selectedStoryIds = $stories->pluck('story_id')->map(fn ($id): int => (int) $id);
        if ($selectedStoryIds->diff($allowedStoryIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'stories' => 'إحدى القصص المختارة غير متاحة ضمن هذه الباقة.',
            ]);
        }

        $storyModels = Story::query()->whereIn('id', $selectedStoryIds)->get()->keyBy('id');
        $regularTotalCents = $selectedStoryIds->sum(function (int $storyId) use ($storyModels): int {
            $story = $storyModels->get($storyId);

            return $story ? (int) round($this->storyPricing->snapshot($story)['effective_price'] * 100) : 0;
        });

        foreach ($package->items as $packageItem) {
            $regularTotalCents += $packageItem->product->effectivePriceCents($packageItem->variant)
                * max(1, (int) $packageItem->quantity);
        }

        $packagePriceCents = (int) round(((float) $package->price) * 100);
        if ($packagePriceCents > $regularTotalCents) {
            throw ValidationException::withMessages([
                'pricing_package_id' => 'سعر الباقة أكبر من مجموع مكوناتها الحالي. راجع إعدادات الباقة قبل إنشاء الطلب.',
            ]);
        }

        $packageDiscountCents = $regularTotalCents - $packagePriceCents;
        $manualDiscountCents = (int) round(max(0, (float) ($validated['discount_amount'] ?? 0)) * 100);
        $manualReason = trim((string) ($validated['discount_reason'] ?? ''));
        $packageReason = 'سعر باقة: '.$package->name;

        $validated['discount_amount'] = ($packageDiscountCents + $manualDiscountCents) / 100;
        $validated['discount_reason'] = implode(' — ', array_filter([$packageReason, $manualReason]));
        $validated['_package_snapshot'] = [
            'id' => $package->id,
            'slug' => $package->slug,
            'name' => $package->name,
            'instance_key' => (string) Str::uuid(),
            'package_price_cents' => $packagePriceCents,
            'regular_total_cents' => $regularTotalCents,
            'created_manually' => true,
        ];
        $validated['_package_product_ids'] = $package->items
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $validated;
    }
}

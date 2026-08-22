<?php

namespace App\Services\Pricing;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Story;
use Illuminate\Support\Facades\DB;

class DefaultPackageInstaller
{
    public function __construct(
        private readonly StoryPricingService $storyPricing,
        private readonly DefaultPackageDeduplicator $deduplicator,
    ) {}

    /** @return array{installed: list<string>, skipped: list<string>} */
    public function install(): array
    {
        $this->deduplicator->deactivateGeneratedDuplicates();

        $story = Story::query()->where('active', true)->orderBy('id')->first();
        if (! $story) {
            return ['installed' => [], 'skipped' => ['لا توجد قصة نشطة لحساب سعر الباقات.']];
        }

        $coloring = $this->findProduct('ktab-tloyn-mkhss-bsor-altfl', '%تلوين%');
        $maze = $this->findProduct('maze-book-level-1', '%متاهات%');
        $storyPrice = $this->storyPricing->effectivePrice($story);

        $definitions = [
            [
                'slug' => 'three-personalized-stories',
                'name' => 'باقة ٣ قصص مخصصة',
                'subtitle' => 'ثلاث مغامرات مختلفة لطفلك بسعر أوفر',
                'description' => 'اختار ٣ قصص مخصصة باسم وصورة طفلك، ويمكن تخصيص كل قصة لنفس الطفل أو لأطفال مختلفين داخل طلب واحد.',
                'story_count' => 3,
                'products' => [],
                'image_path' => 'images/packages/three-personalized-stories.webp',
                'features' => ['٣ قصص مطبوعة ومخصصة', 'اختيار مختلف لكل قصة', 'مراجعة التصميم قبل الطباعة', 'خصم ١٠٪ عن شراء المكونات منفصلة'],
                'badge' => 'وفر ١٠٪',
                'is_featured' => false,
                'sort_order' => 10,
            ],
            [
                'slug' => 'three-stories-coloring-maze',
                'name' => 'باقة ٣ قصص + تلوين + متاهات',
                'subtitle' => 'قصص وأنشطة ممتعة في باقة واحدة',
                'description' => '٣ قصص مخصصة لطفلك مع كتاب تلوين وكتاب متاهات، لتجمع بين القراءة والخيال والأنشطة بعيدًا عن الشاشات.',
                'story_count' => 3,
                'products' => array_filter([$coloring, $maze]),
                'requires_products' => 2,
                'image_path' => 'images/packages/three-stories-coloring-maze.webp',
                'features' => ['٣ قصص مطبوعة ومخصصة', 'كتاب تلوين', 'كتاب متاهات', 'خصم ١٠٪ عن شراء المكونات منفصلة'],
                'badge' => 'الأكثر تنوعًا · وفر ١٠٪',
                'is_featured' => true,
                'sort_order' => 20,
            ],
            [
                'slug' => 'five-stories-coloring-maze',
                'name' => 'باقة ٥ قصص + تلوين + متاهات',
                'subtitle' => 'المكتبة الكاملة لبطل HeroKid',
                'description' => '٥ قصص مخصصة مع كتاب تلوين وكتاب متاهات. مناسبة للعائلات التي تريد مجموعة كبيرة، أو لاختيار قصص لأكثر من طفل في نفس الطلب.',
                'story_count' => 5,
                'products' => array_filter([$coloring, $maze]),
                'requires_products' => 2,
                'image_path' => 'images/packages/five-stories-coloring-maze.webp',
                'features' => ['٥ قصص مطبوعة ومخصصة', 'كتاب تلوين', 'كتاب متاهات', 'خصم ١٠٪ عن شراء المكونات منفصلة'],
                'badge' => 'أفضل قيمة · وفر ١٠٪',
                'is_featured' => false,
                'sort_order' => 30,
            ],
        ];

        $result = ['installed' => [], 'skipped' => []];

        foreach ($definitions as $definition) {
            if (($definition['requires_products'] ?? 0) > count($definition['products'])) {
                $result['skipped'][] = $definition['name'].' — لم يتم العثور على كتاب التلوين وكتاب المتاهات النشطين.';

                continue;
            }

            DB::transaction(function () use ($definition, $storyPrice, &$result): void {
                $regularPrice = ($definition['story_count'] * $storyPrice)
                    + collect($definition['products'])->sum(fn (Product $product): float => $product->effectivePrice());
                $packagePrice = round($regularPrice * 0.90, 2);

                $package = $this->deduplicator->findAdminEquivalent(
                    $definition['name'],
                    $definition['story_count'],
                    $definition['slug'],
                ) ?? PricingPackage::query()->firstOrCreate(
                    ['slug' => $definition['slug']],
                    [
                        'name' => $definition['name'],
                        'subtitle' => $definition['subtitle'],
                        'description' => $definition['description'],
                        'image_path' => $definition['image_path'],
                        'price' => $packagePrice,
                        'regular_price' => round($regularPrice, 2),
                        'currency' => 'ج.م',
                        'features' => $definition['features'],
                        'story_count' => $definition['story_count'],
                        'is_featured' => $definition['is_featured'],
                        'badge' => $definition['badge'],
                        'button_text' => 'اختار قصص الباقة',
                        'sort_order' => $definition['sort_order'],
                        'active' => true,
                        'show_in_store' => true,
                        'show_on_homepage' => true,
                    ],
                );

                if ($package->wasRecentlyCreated) {
                    foreach (array_values($definition['products']) as $index => $product) {
                        $package->items()->create([
                            'product_id' => $product->id,
                            'quantity' => 1,
                            'sort_order' => $index,
                        ]);
                    }
                }

                $result['installed'][] = $package->name;
            });
        }

        return $result;
    }

    private function findProduct(string $preferredSlug, string $arabicNamePattern): ?Product
    {
        return Product::query()->where('is_active', true)->where('slug', $preferredSlug)->first()
            ?? Product::query()->where('is_active', true)->where('name_ar', 'like', $arabicNamePattern)->orderBy('sort_order')->orderBy('id')->first();
    }
}

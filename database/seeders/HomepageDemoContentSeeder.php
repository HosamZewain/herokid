<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\StoryCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Local-only demo catalogue so the homepage can be reviewed with realistic
 * content. Deliberately NOT wired into DatabaseSeeder.
 *
 *   Seed:   php artisan db:seed --class=HomepageDemoContentSeeder
 *
 * Everything it creates is slug-prefixed with "demo-", so removing it is:
 *   Story::where('slug', 'like', 'demo-%')->delete();
 *   Product::where('slug', 'like', 'demo-%')->delete();
 *   StoryCategory::where('slug', 'like', 'demo-%')->delete();
 */
class HomepageDemoContentSeeder extends Seeder
{
    private const PREFIX = 'demo-';

    public function run(): void
    {
        $covers = collect(Storage::disk('public')->files('stories'))
            ->filter(fn (string $path): bool => (bool) preg_match('/\.(jpe?g|png|webp)$/i', $path))
            ->values();

        $storyCategories = collect([
            'مغامرات' => 'demo-adventure',
            'قيم وأخلاق' => 'demo-values',
            'خيال علمي' => 'demo-scifi',
            'حيوانات' => 'demo-animals',
        ])->map(fn (string $slug, string $name) => StoryCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        ));

        $stories = [
            ['بطل الغابة الشجاع', 'مغامرة في قلب الغابة يتعلم فيها طفلك معنى الشجاعة الحقيقية.', 'الشجاعة', '4-6', 'demo-adventure'],
            ['رحلة إلى النجوم', 'ينطلق طفلك في سفينة فضاء ليكتشف كواكب جديدة وأصدقاء من كل مكان.', 'حب الاستكشاف', '5-8', 'demo-scifi'],
            ['كنز الصداقة', 'قصة عن صديقين يبحثان عن كنز فيكتشفان أن الصداقة هي الكنز.', 'الصداقة', '4-7', 'demo-values'],
            ['حارس البحر الصغير', 'يتعلم طفلك كيف يحمي البحر ومخلوقاته من التلوث.', 'المسؤولية', '6-9', 'demo-animals'],
            ['ليلة المصباح السحري', 'مغامرة ليلية مليئة بالدهشة تعلّم الصبر وحسن التصرف.', 'الصبر', '4-6', 'demo-adventure'],
            ['مدرسة الديناصورات', 'يوم دراسي غريب مع أصدقاء من عصور ما قبل التاريخ.', 'التعاون', '5-7', 'demo-animals'],
            ['سر القلعة القديمة', 'لغز شيّق يحلّه طفلك بالملاحظة والتفكير.', 'الذكاء', '7-9', 'demo-adventure'],
            ['أجمل هدية', 'قصة دافئة عن العطاء ومشاركة ما نحب مع الآخرين.', 'العطاء', '3-5', 'demo-values'],
        ];

        foreach ($stories as $index => [$title, $desc, $lesson, $age, $categorySlug]) {
            $story = Story::updateOrCreate(
                ['slug' => self::PREFIX.'story-'.($index + 1)],
                [
                    'title' => $title,
                    'short_desc' => $desc,
                    'full_desc' => $desc,
                    'lesson_value' => $lesson,
                    'age_range' => $age,
                    'language' => $index % 3 === 0 ? 'en' : 'ar',
                    'price' => [349, 399, 379, 429, 359, 389, 419, 329][$index],
                    'active' => true,
                    'cover_image' => $covers->get($index % max($covers->count(), 1)),
                ]
            );

            $category = $storyCategories->get($categorySlug === 'demo-adventure' ? 'مغامرات'
                : ($categorySlug === 'demo-values' ? 'قيم وأخلاق'
                : ($categorySlug === 'demo-scifi' ? 'خيال علمي' : 'حيوانات')));

            if ($category) {
                $story->categories()->syncWithoutDetaching([$category->id]);
            }
        }

        $activities = ProductCategory::where('slug', 'activities-learning')->first();
        $gifts = ProductCategory::where('slug', 'personalized-gifts')->first();

        $products = [
            ['كتاب تلوين باسم طفلك', 'demo-coloring-book', 199, $activities, 'collect_child_details', '٣٢ صفحة تلوين تحمل اسم طفلك ورسومات مستوحاة من شخصيته.'],
            ['كتاب متاهات وألغاز', 'demo-maze-book', 179, $activities, 'none', 'متاهات متدرجة الصعوبة تنمّي التركيز بعيدًا عن الشاشات.'],
            ['دفتر أنشطة الحروف', 'demo-letters-book', 159, $activities, 'none', 'تدريبات ممتعة على الحروف العربية والإنجليزية.'],
            ['بوستر بطل العائلة', 'demo-hero-poster', 249, $gifts, 'collect_child_details', 'بوستر مطبوع بجودة عالية يحمل صورة طفلك كبطل خارق.'],
            ['ميدالية باسم طفلك', 'demo-name-keychain', 99, $gifts, 'collect_child_details', 'ميدالية خشبية محفور عليها اسم طفلك.'],
            ['علبة هدية فاخرة', 'demo-gift-box', 149, $gifts, 'none', 'تغليف هدايا أنيق يحوّل أي طلب إلى مفاجأة.'],
        ];

        foreach ($products as [$name, $slug, $price, $category, $mode, $desc]) {
            if (! $category) {
                continue;
            }

            Product::updateOrCreate(
                ['slug' => $slug],
                [
                    'product_category_id' => $category->id,
                    'name_ar' => $name,
                    'short_description_ar' => $desc,
                    'description_ar' => $desc,
                    'price_cents' => $price * 100,
                    'is_active' => true,
                    'is_featured' => in_array($slug, ['demo-coloring-book', 'demo-hero-poster'], true),
                    'fulfillment_type' => 'physical',
                    'purchase_mode' => 'standalone',
                    'personalization_mode' => $mode,
                    'inventory_mode' => 'no_tracking',
                    'age_groups' => ['4-6', '7-9'],
                ]
            );
        }

        $this->command?->info('Demo catalogue seeded: 8 stories, 6 products (all slugs prefixed "demo-").');
    }
}

<?php

namespace App\Services\SalesAssistant;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Story;
use App\Services\Catalog\CustomerKnowledge;
use App\Services\Pricing\StoryPricingService;
use App\Services\Stories\StoryLanguageAvailability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesCatalog
{
    public const CACHE_KEY = 'sales-assistant:public-catalog:v1';

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $profiles = DB::table('sales_catalog_profiles')->pluck('keywords', 'catalog_key');
            $items = [];
            foreach (Story::where('active', true)->with(['categories:id,name', 'sceneTemplates:id,story_id,scene_number,english_male_text_template,english_female_text_template'])->get() as $s) {
                $key = 'story:'.$s->id;
                $items[$key] = ['key' => $key, 'kind' => 'story', 'id' => $s->id,
                    'title' => CustomerKnowledge::text($s->title, 150), 'description' => CustomerKnowledge::text($s->short_desc, 500),
                    'value' => CustomerKnowledge::text($s->lesson_value, 250), 'age' => $s->age_range,
                    'gender' => $s->gender ?: 'both', 'language' => $s->language ?: 'ar',
                    'languages' => app(StoryLanguageAvailability::class)->english($s) ? ['ar', 'en'] : ['ar'],
                    'categories' => $s->categories->pluck('name')->all(), 'keywords' => $profiles[$key] ?? '',
                    'price_cents' => (int) round(app(StoryPricingService::class)->effectivePrice($s) * 100),
                    'image' => $s->cover_url, 'url' => route('stories.show', $s->slug)];
            }
            if (setting('shop_enabled', '1') === '1') {
                foreach (Product::publiclyVisible()->with(['category', 'activeVariants'])->withCount('variants')->get() as $p) {
                    $variant = $p->activeVariants->filter(fn ($v) => $p->hasStock(1, $v))->sortBy(fn ($v) => $p->effectivePriceCents($v))->first();
                    if (($p->variants_count > 0 && ! $variant) || ! $p->hasStock(1, $variant)) {
                        continue;
                    }
                    $key = 'product:'.$p->id;
                    $items[$key] = ['key' => $key, 'kind' => 'product', 'id' => $p->id,
                        'title' => CustomerKnowledge::text($p->name_ar, 150), 'description' => CustomerKnowledge::text($p->description_ar, 500),
                        'value' => CustomerKnowledge::text(implode(' ', array_filter(array_map(fn ($v) => is_string($v) ? $v : '', $p->features ?? []))), 250),
                        'age' => implode('، ', $p->age_groups ?? []), 'gender' => 'both', 'language' => 'ar',
                        'categories' => [$p->category?->name_ar], 'keywords' => $profiles[$key] ?? '',
                        'variants' => $p->activeVariants->filter(fn ($v) => $p->hasStock(1, $v))->take(20)->map(fn ($v) => ['id' => $v->id, 'name' => CustomerKnowledge::text($v->name_ar, 80), 'price_cents' => $p->effectivePriceCents($v)])->values()->all(),
                        'price_from' => $p->activeVariants->count() > 1,
                        'price_cents' => $p->effectivePriceCents($variant), 'image' => $p->featured_image_url,
                        'url' => route('shop.product.show', $p), 'requires_story' => $p->purchase_mode === 'add_on_only' || $p->personalization_mode === 'inherit_from_linked_story'];
                }
                foreach (PricingPackage::where('active', true)->where('show_in_store', true)->with(['items.product.category', 'items.variant'])->get() as $p) {
                    if (! $p->availableForPurchase()) {
                        continue;
                    }
                    $key = 'package:'.$p->id;
                    $items[$key] = ['key' => $key, 'kind' => 'package', 'id' => $p->id,
                        'title' => CustomerKnowledge::text($p->name, 150), 'description' => CustomerKnowledge::text($p->description, 500),
                        'value' => '', 'age' => '', 'gender' => 'both', 'language' => 'ar', 'categories' => ['باقات'],
                        'keywords' => $profiles[$key] ?? '', 'price_cents' => (int) round($p->price * 100),
                        'image' => null, 'url' => route('shop.package.show', $p)];
                }
            }

            return $items;
        });
    }

    public function item(string $key): array
    {
        return $this->all()[$key] ?? abort(404);
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(CustomerKnowledge::text($value, 6000));
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value));
    }

    public function search(string $query, ?int $age = null, ?string $gender = null, ?int $budgetCents = null, ?string $language = null): array
    {
        $query = $this->normalize($query);
        $terms = array_values(array_filter(explode(' ', $query), fn ($t) => mb_strlen($t) >= 3));
        $groups = [
            ['صلاه', 'الصلاه', 'يصلي', 'الصلاه', 'المسجد', 'مسجد', 'الفجر', 'عباده'],
            ['كوره', 'الكره', 'القدم', 'كرويه', 'الكوره', 'فوتبول', 'football', 'صلاح', 'هالاند'],
            ['الوالدين', 'والديه', 'ماما', 'طاعه', 'والدته', 'والده', 'بابا'],
            ['تلوين', 'التلوين', 'رسم', 'الابداع', 'ابداع', 'coloring'],
            ['بازل', 'البازل', 'تركيز', 'التركيز', 'puzzle', 'الشاشات', 'تلفزيون', 'تليفزيون', 'التليفزيون', 'شقي', 'طاقه', 'مهارات'],
        ];
        foreach ($groups as $group) {
            if (array_intersect($terms, $group)) {
                $terms = array_merge($terms, $group);
            }
        }
        $terms = array_unique($terms);
        $all = $this->all();
        $scored = [];
        foreach ($all as $key => $item) {
            if ($language && $item['kind'] === 'story' && ! in_array($language, $item['languages'] ?? [$item['language']], true)) {
                continue;
            }
            if ($gender && ! in_array($item['gender'], ['both', 'any', $gender], true)) {
                continue;
            }
            if ($budgetCents !== null && $item['price_cents'] > $budgetCents) {
                continue;
            }
            if ($age !== null && preg_match_all('/(\d+)\s*[-–إلى]+\s*(\d+)/u', CustomerKnowledge::text($item['age']), $ranges, PREG_SET_ORDER)
                && ! collect($ranges)->contains(fn ($range) => $age >= (int) $range[1] && $age <= (int) $range[2])) {
                continue;
            }
            $score = 0;
            foreach (['title' => 5, 'value' => 5, 'keywords' => 6, 'description' => 1, 'categories' => 2] as $field => $weight) {
                $words = explode(' ', $this->normalize(is_array($item[$field]) ? implode(' ', $item[$field]) : (string) $item[$field]));
                $score += count(array_intersect($terms, $words)) * $weight;
            }
            if ($score > 0) {
                $scored[$key] = $score;
            }
        }
        arsort($scored);

        return array_map(fn ($key) => $all[$key], array_keys(array_slice($scored, 0, 12, true)));
    }

    public function publicFacts(array $items): array
    {
        return array_map(fn ($i) => array_intersect_key($i, array_flip(['key', 'title', 'description', 'value', 'age', 'gender', 'language', 'price_cents', 'requires_story', 'variants', 'languages', 'price_from'])), $items);
    }

    public function cards(array $choices): array
    {
        $all = $this->all();
        $cards = [];
        foreach (array_slice($choices, 0, 3) as $choice) {
            $key = $choice['key'] ?? '';
            if (isset($all[$key])) {
                $cards[] = array_diff_key($all[$key], array_flip(['keywords', 'categories', 'value'])) + ['reason' => app(SalesPrivacy::class)->safeReply($choice['reason'] ?? '')];
            }
        }

        return $cards;
    }
}

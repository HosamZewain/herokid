<?php

namespace App\Services\Catalog;

use App\Models\DeliveryGovernorate;
use App\Models\FaqItem;
use App\Models\PricingPackage;
use App\Models\PricingPackageProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Story;
use App\Services\Pricing\StoryPricingService;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoboDeskFeed
{
    private Collection $stories;

    private Collection $products;

    private Collection $packages;

    private StoryPricingService $pricing;

    private CustomerKnowledge $copy;

    private $updated;

    public function __construct()
    {
        $this->pricing = new StoryPricingService;
        $this->copy = new CustomerKnowledge;
    }

    public function catalogQuery()
    {
        $stories = Story::query()->where('active', true)->select('id')->selectRaw("'story' as kind");
        $products = Product::query()->publiclyVisible()->select('products.id')->selectRaw("'product' as kind");
        $packages = $this->packagesQuery()->select('id')->selectRaw("'package' as kind");

        return DB::query()->fromSub($stories->unionAll($products)->unionAll($packages), 'catalog')->orderBy('kind')->orderBy('id');
    }

    private function load(): void
    {
        $this->stories = Story::query()->where('active', true)->with('categories')->orderBy('id')->get()->keyBy('id');
        $this->products = Product::query()->publiclyVisible()->with(['category', 'activeVariants'])->orderBy('id')->get()->keyBy('id');
        $this->packages = $this->packagesQuery()
            ->with(['items.product', 'items.variant', 'eligibleStories'])->orderBy('id')->get()->keyBy('id');
        $this->updated = collect([$this->stories->max('updated_at'), $this->products->max('updated_at'),
            $this->packages->max('updated_at'), Setting::max('updated_at'), FaqItem::max('updated_at'),
            ProductVariant::max('updated_at'), PricingPackageProduct::max('updated_at')])->filter()->max();
    }

    private function packagesQuery()
    {
        return PricingPackage::query()->active()->purchasable()->where('show_in_store', true)
            ->whereDoesntHave('items', fn ($q) => $q->whereDoesntHave('product', fn ($p) => $p->publiclyVisible())
                ->orWhere(fn ($item) => $item->whereNotNull('product_variant_id')->whereDoesntHave('variant', fn ($v) => $v->where('is_active', true))));
    }

    public function catalog(int $page): array
    {
        $paginator = $this->catalogQuery()->paginate(50, ['*'], 'page', $page);
        $this->load();
        $paginator->setPath($this->url('/api/feeds/robodesk/catalog'));
        $paginator->setCollection($paginator->getCollection()->map(fn ($row) => match ($row->kind) {
            'story' => $this->story($this->stories[$row->id]),
            'product' => $this->product($this->products[$row->id]),
            'package' => $this->package($this->packages[$row->id]),
        }));

        return $paginator->toArray();
    }

    private function pool(PricingPackage $package): Collection
    {
        return $package->applies_to_all_stories ? $this->stories : $this->stories->only($package->eligibleStories->modelKeys());
    }

    private function containing(string $kind, int $id): Collection
    {
        return $this->packages->filter(fn ($package) => $kind === 'story'
            ? $package->story_count > 0 && $this->pool($package)->contains('id', $id)
            : $package->items->contains('product_id', $id));
    }

    private function packageNames(string $kind, int $id): string
    {
        return $this->containing($kind, $id)->map(fn ($package) => '«'.$package->name.'» ('.$package->componentSummary().') بـ '.CustomerKnowledge::money((float) $package->price).' ج.م')->implode('؛ ');
    }

    private function story(Story $story): array
    {
        $snapshot = $this->pricing->snapshot($story);
        $url = $this->url('/stories/'.$story->slug);
        $packages = $this->packageNames('story', $story->id);
        $lines = [
            'قصة «'.$story->title.'» — قصة مخصصة باسم طفلك وصورة وشه، بالعربي أو الإنجليزي.',
            'البطل: '.$this->gender($story).'. السن المناسب: '.CustomerKnowledge::text($story->age_range ?: 'كل الأعمار').'. التصنيف: '.$story->categories->pluck('name')->implode('، ').'.',
            'عن القصة: '.CustomerKnowledge::text($story->short_desc ?: $story->full_desc, 220),
            'الدرس المستفاد: '.CustomerKnowledge::text($story->lesson_value, 100),
            $this->priceLine($snapshot['effective_price'], $snapshot['regular_price']),
            $packages ? 'متاحة كمان في الباقات: '.$packages : null,
            'للطلب: اسم الطفل وعمره و'.config('photo_uploads.min_files', 2).'–'.config('photo_uploads.max_files', 3).' صور واضحة للوجه، وبنبعتلك معاينة قبل الطباعة.',
        ];
        // This is a public category, not a guess about the child's gender from a name.
        if ($story->categories->contains(fn ($category) => str_contains($category->name, 'كرة القدم'))
            || preg_match('/رونالدو|محمد صلاح|ميسي|هالاند|مبابي|ronaldo|messi/i', $story->title)) {
            $lines[] = 'قصة خيالية غير رسمية ولا تعني شراكة مع اللاعب أو أي فريق.';
        }
        $lines[] = 'الرابط: '.$url;

        return $this->item('story', $story->slug, $story->title, $story->short_desc ?: $story->full_desc, $url, $lines,
            'STORY-'.$story->id, $snapshot['effective_price'], 'in_stock', $story->cover_url, $story->updated_at);
    }

    private function product(Product $product): array
    {
        $variant = $product->activeVariants->first();
        $price = $product->effectivePriceCents($variant) / 100;
        $personalized = $product->personalization_mode && $product->personalization_mode !== 'none';
        $url = $this->url('/shop/product/'.$product->slug);
        $fieldsSchema = ProductPersonalizationSchema::enabledFields(ProductPersonalizationSchema::forProduct($product));
        $personalizationLabel = isset($fieldsSchema['photos']) ? 'منتج مخصص باسم وصورة الطفل' : 'منتج مخصص ببيانات الطفل';
        $lines = ['«'.$product->name_ar.'» — '.($personalized ? $personalizationLabel : 'منتج جاهز يُشترى مباشرة').'.',
            CustomerKnowledge::text($product->short_description_ar ?: $product->description_ar, 350)];
        if ($product->activeVariants->isNotEmpty()) {
            $lines[] = 'الاختيارات: '.$product->activeVariants->map(fn ($v) => $v->name_ar.' بـ '.CustomerKnowledge::money($product->effectivePriceCents($v) / 100).' ج.م')->implode('؛ ').'.';
        }
        $lines[] = 'السن المناسب: '.CustomerKnowledge::text($product->ageLabel()).'. '.$this->priceLine($price, $product->regularPriceCents($variant) / 100);
        if ($personalized) {
            $fields = collect(ProductPersonalizationSchema::enabledFields(ProductPersonalizationSchema::forProduct($product)))
                ->filter(fn ($field) => $field['required'])->map(fn ($field) => $field['type'] === 'photos'
                    ? $field['min_files'].'–'.$field['max_files'].' صور واضحة للوجه' : $field['label'])->implode('، ');
            $lines[] = 'للطلب: '.($fields ?: 'بيانات الطفل المرتبطة بالقصة').'، وبنبعتلك معاينة قبل الإنتاج.';
        }
        if ($packages = $this->packageNames('product', $product->id)) {
            $lines[] = 'موجود كمان في '.$packages.'.';
        }
        $lines[] = 'الرابط: '.$url;
        $available = $product->activeVariants->isEmpty() ? $product->hasStock() : $product->activeVariants->contains(fn ($v) => $product->hasStock(1, $v));

        return $this->item('product', $product->slug, $product->name_ar, $product->short_description_ar ?: $product->description_ar,
            $url, $lines, $product->sku ?: 'PRODUCT-'.$product->id, $price, $available ? 'in_stock' : 'out_of_stock', $product->featured_image_url, $product->updated_at);
    }

    private function package(PricingPackage $package): array
    {
        $pool = $this->pool($package);
        $url = $this->url('/shop/package/'.$package->slug);
        $lines = ['«'.$package->name.'»: '.CustomerKnowledge::text($package->description, 220)];
        if ($package->story_count > 0) {
            $lines[] = 'بتختار '.$package->story_count.' قصص '.($package->applies_to_all_stories ? 'من أي قصة من الـ '.$pool->count().' قصة' : 'من القصص دي بس: '.$pool->pluck('title')->implode('، ')).'.';
        }
        if ($package->items->isNotEmpty()) {
            $lines[] = 'وكمان فيها: '.$package->items->map(fn ($item) => $item->quantity.' × '.$item->product?->name_ar.($item->variant ? ' — '.$item->variant->name_ar : ''))->implode('؛ ').'.';
        }
        $lines[] = (new PackageComparison)->sentence($package, $pool);
        $lines[] = 'بيانات طفل واحد لكل الباقة.';
        $lines[] = 'الرابط: '.$url;

        return $this->item('package', $package->slug, $package->name, $package->description, $url, $lines, 'PACKAGE-'.$package->id,
            (float) $package->price, $package->availableForPurchase() && ($package->story_count === 0 || $pool->isNotEmpty()) ? 'in_stock' : 'out_of_stock', $package->image_url, $package->updated_at);
    }

    private function gender(Story $story): string
    {
        return match ($story->hero_gender) {
            'boy' => 'ولد', 'girl' => 'بنت', default => 'ولد أو بنت'
        };
    }

    private function priceLine(float $price, float $regular): string
    {
        return 'السعر: '.CustomerKnowledge::money($price).' ج.م'.($regular > $price ? ' بدل '.CustomerKnowledge::money($regular).' ج.م (عرض خاص)' : '').'.';
    }

    private function url(string $path): string
    {
        return 'https://hero-kid.com'.$path;
    }

    private function item(string $type, string $slug, string $name, ?string $description, string $url, array $lines,
        ?string $sku = null, ?float $price = null, ?string $stock = null, ?string $image = null, $updated = null): array
    {
        return [
            'id' => $type.':'.$slug, 'type' => $type, 'sku' => $sku, 'name' => CustomerKnowledge::text($name),
            'description' => CustomerKnowledge::text($description), 'price' => $price, 'currency' => 'EGP',
            'stock' => $stock, 'url' => $url,
            'image' => $image && preg_match('~^https?://~i', $image) ? preg_replace('~^http://~i', 'https://', $image) : null,
            'updated_at' => ($this->updated ?? $updated) ? Carbon::parse($this->updated ?? $updated)->utc()->format('Y-m-d\TH:i:s\Z') : null,
            'rag_text' => implode("\n", array_map(fn ($line) => CustomerKnowledge::text($line, 10000), array_values(array_filter($lines)))),
        ];
    }

    public function guide(int $page): array
    {
        $this->load();
        $articles = collect();
        $updated = collect([$this->stories->max('updated_at'), $this->products->max('updated_at'), $this->packages->max('updated_at'), Setting::max('updated_at'), FaqItem::max('updated_at')])->filter()->max();
        $add = function (string $type, string $slug, string $name, array $lines, string $url = '/shop') use ($articles, $updated): void {
            // Chunk large indexes without dropping entries; every article identifies its own topic.
            $chunks = [];
            $chunk = [];
            $length = mb_strlen($name);
            foreach ($lines as $line) {
                if ($chunk && (count($chunk) >= 30 || $length + mb_strlen($line) > 1150)) {
                    $chunks[] = $chunk;
                    $chunk = [];
                    $length = mb_strlen($name);
                }
                $chunk[] = $line;
                $length += mb_strlen($line) + 1;
            }
            if ($chunk) {
                $chunks[] = $chunk;
            }
            foreach ($chunks as $index => $chunk) {
                $articles->push($this->item($type, $slug.'-'.($index + 1), $name.' — '.($index + 1), $name, $this->url($url), [$name, ...$chunk], updated: $updated));
            }
        };
        $add('index', 'overview', 'دليل كتالوج HeroKid', [
            $this->stories->count().' قصة، '.$this->products->count().' منتج، '.$this->packages->count().' باقة.',
            'تصنيفات القصص: '.$this->stories->flatMap->categories->pluck('name')->unique()->implode('، '),
            'تصنيفات المنتجات: '.$this->products->pluck('category.name_ar')->unique()->implode('، '),
            ...$this->stories->filter(fn ($s) => $this->pricing->hasActiveOffer($s))->map(fn ($s) => $this->priceLine($this->pricing->effectivePrice($s), $this->pricing->regularPrice($s)))->unique()->values()->all(),
        ]);
        foreach ($this->stories->flatMap->categories->unique('id') as $category) {
            $add('index', 'stories-category-'.$category->slug, 'قصص '.$category->name, $this->stories->filter(fn ($s) => $s->categories->contains('id', $category->id))->map(fn ($s) => $this->storyLine($s))->values()->all());
        }
        foreach ($this->stories->groupBy(fn ($s) => $s->age_range ?: 'كل الأعمار') as $age => $stories) {
            $add('index', 'stories-age-'.substr(hash('sha256', $age), 0, 12), 'قصص سن '.$age, $stories->map(fn ($s) => $this->storyLine($s))->values()->all());
        }
        foreach (['boy' => 'للأولاد', 'girl' => 'للبنات'] as $gender => $label) {
            $add('index', 'stories-'.$gender, 'قصص '.$label, $this->stories->filter(fn ($s) => in_array($s->hero_gender, [$gender, 'any'], true))->map(fn ($s) => $this->storyLine($s))->values()->all());
        }
        $add('index', 'packages', 'مقارنة الباقات — طفل واحد لكل باقة', $this->packages->map(fn ($p) => '«'.$p->name.'»: '.$p->componentSummary().'. '.(new PackageComparison)->sentence($p, $this->pool($p)))->values()->all(), '/pricing');
        foreach ($this->products->groupBy('product_category_id') as $products) {
            $category = $products->first()->category;
            $add('index', 'products-'.$category->slug, 'منتجات '.$category->name_ar, $products->map(fn ($p) => '«'.$p->name_ar.'» — '.CustomerKnowledge::text($p->ageLabel()).' — '.CustomerKnowledge::text($p->short_description_ar, 100).' — '.CustomerKnowledge::money($p->effectivePriceCents($p->activeVariants->first()) / 100).' ج.م')->values()->all());
        }
        foreach ($this->products->filter(fn ($p) => preg_match('/المتاهات|الألغاز/u', $p->name_ar))->groupBy(fn ($p) => $p->ageLabel()) as $age => $activities) {
            $add('index', 'activities-age-'.substr(hash('sha256', $age), 0, 12), 'كتب الأنشطة والمستويات لسن '.CustomerKnowledge::text($age),
                $activities->map(fn ($p) => '«'.$p->name_ar.'» — '.CustomerKnowledge::text($p->ageLabel()).' — '.CustomerKnowledge::money($p->effectivePriceCents($p->activeVariants->first()) / 100).' ج.م')->values()->all());
        }
        $settings = Setting::query()->pluck('value', 'key');
        $steps = [];
        foreach (range(1, 5) as $step) {
            $steps[] = $step.'. '.($settings['hiw_step'.$step.'_title'] ?? '').': '.$this->copy->render($settings['hiw_step'.$step.'_desc'] ?? '');
        }
        $add('guide', 'ordering', 'إزاي تطلب من HeroKid؟', $steps, '/how-it-works');
        foreach (['photos' => 'صور الطفل المطلوبة', 'preview' => 'المعاينة والتعديلات', 'payment' => 'الدفع', 'delivery' => 'الإنتاج والتوصيل داخل مصر', 'privacy' => 'خصوصية صور الطفل', 'tracking' => 'متابعة الطلب', 'library' => 'مكتبة القصص المجانية', 'identity' => 'أداة هوية الطفل'] as $key => $name) {
            if ($text = $this->copy->policy($key)) {
                $add('guide', $key, $name, [$text], match ($key) {
                    'privacy' => '/privacy', 'library' => '/story-library', 'identity' => '/child-identity', 'tracking' => '/track', default => '/faq'
                });
            }
        }
        $fees = DeliveryGovernorate::query()->with('country')->where('active', true)->whereHas('country', fn ($q) => $q->where('active', true)
            ->where(fn ($country) => $country->whereIn('code', ['EG', 'EGY'])->orWhere('name', 'مصر')))->orderBy('name')->get();
        $add('guide', 'shipping-fees', 'رسوم التوصيل حسب المحافظة', $fees->map(fn ($g) => $g->name.': '.CustomerKnowledge::money($g->effectiveDeliveryFee()).' ج.م')->all());
        foreach (FaqItem::query()->where('active', true)->orderBy('id')->get() as $faq) {
            $add('guide', 'faq-'.substr(hash('sha256', $faq->question), 0, 16), $faq->question, [$this->copy->render($faq->answer)], '/faq');
        }
        $contact = collect(['site_email', 'contact_phone', 'whatsapp_number'])->map(fn ($key) => $settings[$key] ?? null)->filter()->values()->all();
        $add('guide', 'contact', 'تواصل مع HeroKid', [...$contact, 'تواصل معنا: '.$this->url('/contact')], '/contact');
        $articles = $articles->sortBy('id')->values();

        return (new LengthAwarePaginator($articles->forPage($page, 50)->values(), $articles->count(), 50, $page,
            ['path' => $this->url('/api/feeds/robodesk/guide')]))->toArray();
    }

    private function storyLine(Story $story): string
    {
        $words = preg_split('/\s+/u', CustomerKnowledge::text($story->lesson_value));

        return '«'.$story->title.'» — '.$this->gender($story).' — '.($story->age_range ?: 'كل الأعمار').' — '.implode(' ', array_slice($words, 0, 5)).' — '.CustomerKnowledge::money($this->pricing->effectivePrice($story)).' ج.م';
    }
}

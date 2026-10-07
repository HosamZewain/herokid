@if($faqs->isNotEmpty())
    @push('schema')
        @php
            $homeFaqSchema = [
                '@context' => 'https://schema.org', '@type' => 'FAQPage',
                'mainEntity' => $faqs->map(fn ($faq) => [
                    '@type' => 'Question', 'name' => $faq->question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answer],
                ])->values()->all(),
            ];
        @endphp
        <script type="application/ld+json">@json($homeFaqSchema, \App\Support\Seo::jsonFlags())</script>
    @endpush
@endif
@if($homeCatalogItems->isNotEmpty())
    @push('schema')
        @php
            $homeCatalogSchema = [
                '@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'اختيارات HeroKid',
                'numberOfItems' => $homeCatalogItems->count(),
                'itemListElement' => $homeCatalogItems->map(fn ($item, $index) => [
                    '@type' => 'ListItem', 'position' => $index + 1,
                    'item' => array_filter([
                        '@type' => 'Product', 'name' => $item->title,
                        'url' => \App\Support\Seo::url($item->detailUrl),
                        'image' => $item->imageUrl ? \App\Support\Seo::imageUrl($item->imageUrl) : null,
                        'description' => $item->shortDescription ?: null,
                        'brand' => ['@type' => 'Brand', 'name' => 'HeroKid'],
                        'offers' => $item->price > 0 ? ['@type' => 'Offer', 'price' => number_format($item->price, 2, '.', ''), 'priceCurrency' => 'EGP', 'url' => \App\Support\Seo::url($item->detailUrl)] : null,
                    ]),
                ])->values()->all(),
            ];
        @endphp
        <script type="application/ld+json">@json($homeCatalogSchema, \App\Support\Seo::jsonFlags())</script>
    @endpush
@endif

@props(['alt' => 'HeroKid', 'sizes' => '192px'])
@php($logo = \App\Support\SiteBranding::presentation())
<img src="{{ $logo['src'] }}" srcset="{{ $logo['srcset'] }}" sizes="{{ $sizes }}"
    width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $alt }}"
    {{ $attributes->merge(['class' => 'object-contain', 'decoding' => 'async']) }}>

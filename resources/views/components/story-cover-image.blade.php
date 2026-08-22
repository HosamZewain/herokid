@props([
    'src' => null,
    'alt' => '',
    'fallback' => \App\Support\StoryCover::fallbackUrl(),
    'width' => null,
    'height' => null,
    'loading' => null,
    'fetchpriority' => null,
])

@php
    $originalSrc = trim((string) $src);
    $fallbackSrc = trim((string) $fallback);
    $initialSrc = $originalSrc !== '' ? $originalSrc : $fallbackSrc;
@endphp

<img
    src="{{ $initialSrc }}"
    alt="{{ $alt }}"
    @if($width) width="{{ $width }}" @endif
    @if($height) height="{{ $height }}" @endif
    @if($loading) loading="{{ $loading }}" @endif
    @if($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
    data-story-cover
    @if($originalSrc !== '') data-original-src="{{ $originalSrc }}" @endif
    data-fallback-src="{{ $fallbackSrc }}"
    data-cover-retry-state="{{ $originalSrc !== '' ? 'original' : 'fallback' }}"
    @if($originalSrc !== '') onerror="window.HeroKidStoryCover?.handleError(this)" @endif
    {{ $attributes }}>

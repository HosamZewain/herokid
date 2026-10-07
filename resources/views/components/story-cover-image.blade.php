@props([
    'src' => null,
    'alt' => '',
    'fallback' => \App\Support\StoryCover::fallbackUrl(),
    'width' => null,
    'height' => null,
    'loading' => null,
    'fetchpriority' => null,
    'sizes' => '(min-width: 1024px) 320px, (min-width: 640px) 50vw, 100vw',
    'preferredWidth' => 640,
])

@php
    $originalSrc = trim((string) $src);
    $fallbackSrc = trim((string) $fallback);
    $initialSrc = $originalSrc !== '' ? $originalSrc : $fallbackSrc;
@endphp

<x-public-image
    :src="$initialSrc"
    :alt="$alt"
    :sizes="$sizes"
    :preferred-width="$preferredWidth"
    :width="$width"
    :height="$height"
    :loading="$loading"
    :fetchpriority="$fetchpriority"
    data-story-cover
    :data-original-src="$originalSrc ?: null"
    data-fallback-src="{{ $fallbackSrc }}"
    data-cover-retry-state="{{ $originalSrc !== '' ? 'original' : 'fallback' }}"
    :onerror="$originalSrc !== '' ? 'window.HeroKidStoryCover?.handleError(this)' : null"
    {{ $attributes }} />

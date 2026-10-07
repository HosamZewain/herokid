@props(['src', 'alt' => '', 'sizes' => '100vw', 'preferredWidth' => 640])
@php
    $display = app(\App\Services\Images\PublicImageVariants::class)->presentation($src, (int) $preferredWidth);
@endphp
<img src="{{ $display['src'] }}" alt="{{ $alt }}"
    @if($display['srcset'] !== '') srcset="{{ $display['srcset'] }}" sizes="{{ $sizes }}" @endif
    data-public-image-original="{{ $display['original'] }}" decoding="async"
    {{ $attributes->merge(['onerror' => "this.removeAttribute('srcset'); this.parentElement?.querySelectorAll('source').forEach(source => source.removeAttribute('srcset')); this.onerror=null; this.src=this.dataset.publicImageOriginal;"]) }}>

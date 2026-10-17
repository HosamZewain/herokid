<div class="hk-app-stores" aria-label="تحميل تطبيق HeroKid">
    @foreach(['ios' => ['App Store', 'للـ iPhone وiPad'], 'android' => ['Google Play', 'لأجهزة Android']] as $platform => [$store, $device])
    <a href="{{ route('app.store', $platform) }}" class="hk-app-store" data-store-link="{{ $platform }}" data-download-tracked="true" aria-label="تحميل HeroKid من {{ $store }}">
        <span class="hk-app-store-icon" aria-hidden="true">@if($platform === 'ios')<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.1 12.5c0-2 1.6-3 1.7-3.1-1-1.5-2.5-1.7-3.1-1.7-1.3-.2-2.5.8-3.2.8-.6 0-1.6-.8-2.7-.8-1.4 0-2.7.8-3.4 2-1.4 2.4-.4 6 1 7.9.6.9 1.4 1.9 2.5 1.8 1 0 1.4-.6 2.7-.6s1.6.6 2.7.6c1.1 0 1.8-.9 2.4-1.8.8-1.1 1.2-2.2 1.2-2.3-.1 0-1.8-.7-1.8-2.8ZM15 6.4c.5-.7.9-1.6.8-2.4-.8 0-1.8.6-2.4 1.2-.5.5-1 1.5-.8 2.3.9.1 1.8-.4 2.4-1.1Z"/></svg>@else<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="m7 3 14 9L7 21V3Z"/><path d="m7 3 9 13M7 21l9-13"/></svg>@endif</span>
        <span><small>{{ $device }}</small><strong dir="ltr">{{ $store }}</strong></span>
        <span class="hk-app-store-badge" data-store-recommended hidden>الأنسب لجهازك</span>
    </a>
    @endforeach
</div>

@php
    $configuredUrl = trim((string) ($settings['whatsapp_url'] ?? ''));
    $configuredNumber = \App\Support\Phone::forWhatsApp($settings['whatsapp_number'] ?? null);
    $baseUrl = $configuredUrl !== ''
        ? $configuredUrl
        : ($configuredNumber ? 'https://wa.me/'.$configuredNumber : null);
    $message = 'مرحباً، أريد الاستفسار عن خدمات HeroKid';
    $whatsappUrl = $baseUrl
        ? $baseUrl.(str_contains($baseUrl, '?') ? '&' : '?').'text='.rawurlencode($message)
        : null;
@endphp

@if($whatsappUrl)
    <a href="{{ $whatsappUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        data-floating-whatsapp
        aria-label="تواصل مع HeroKid عبر واتساب"
        class="group fixed bottom-[calc(1rem+env(safe-area-inset-bottom))] left-4 z-40 inline-flex min-h-14 min-w-14 items-center justify-center gap-2 rounded-full bg-[#25D366] px-4 text-white shadow-xl shadow-emerald-950/20 transition duration-200 hover:-translate-y-0.5 hover:bg-[#1fbd5a] hover:shadow-2xl focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300 focus-visible:ring-offset-2 sm:bottom-6 sm:left-6 sm:px-5">
        <svg class="h-7 w-7 shrink-0" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
            <path d="M16.04 3A12.85 12.85 0 0 0 5.12 22.64L3.3 29l6.51-1.71A12.85 12.85 0 1 0 16.04 3Zm0 23.5a10.65 10.65 0 0 1-5.43-1.49l-.39-.23-3.86 1.02 1.03-3.76-.25-.39a10.64 10.64 0 1 1 8.9 4.85Zm5.84-7.98c-.32-.16-1.9-.94-2.19-1.05-.3-.1-.51-.16-.72.16-.22.32-.83 1.05-1.02 1.26-.19.22-.38.24-.7.08-.32-.16-1.35-.5-2.57-1.59a9.68 9.68 0 0 1-1.78-2.22c-.19-.32-.02-.5.14-.66.14-.14.32-.38.48-.56.16-.19.22-.32.32-.54.11-.21.05-.4-.03-.56-.08-.16-.72-1.74-.99-2.38-.26-.62-.53-.54-.72-.55h-.62c-.22 0-.56.08-.86.4-.29.32-1.12 1.1-1.12 2.68 0 1.58 1.15 3.11 1.31 3.32.16.22 2.26 3.46 5.48 4.85.77.33 1.37.53 1.84.68.77.24 1.47.21 2.02.13.62-.09 1.9-.78 2.16-1.53.27-.75.27-1.4.19-1.54-.08-.13-.3-.21-.62-.37Z"/>
        </svg>
        <span class="hidden text-sm font-black sm:inline">تواصل معنا</span>
    </a>
@endif

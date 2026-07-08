<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">تواصل مع فريق HeroKid — دعم العملاء على مدار الساعة</x-slot>
<x-slot name="pageDescription">تواصل مع فريق HeroKid لأي استفسار عن قصص الأطفال المخصصة أو حالة طلبك. نرد خلال ساعات العمل عبر الواتساب أو البريد الإلكتروني.</x-slot>

@push('schema')
@php
    $contactSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'ContactPage',
        'name' => 'تواصل مع HeroKid',
        'url' => \App\Support\Seo::url('/contact'),
        'description' => 'صفحة تواصل HeroKid — دعم العملاء لقصص الأطفال المخصصة',
        'mainEntity' => [
            '@type' => 'LocalBusiness',
            'name' => 'HeroKid',
            'telephone' => ! empty($settings['whatsapp_number']) ? '+' . $settings['whatsapp_number'] : null,
            'email' => $settings['site_email'] ?? null,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $settings['address_street'] ?? '',
                'addressLocality' => $settings['address_city'] ?? '',
                'addressCountry' => 'EG',
            ],
            'openingHours' => 'Sa-Th 09:00-21:00',
            'url' => \App\Support\Seo::url('/'),
        ],
    ];
@endphp
<script type="application/ld+json">
@json($contactSchema, \App\Support\Seo::jsonFlags())
</script>
@endpush

    <!-- Header -->
    <div class="relative bg-gradient-to-br from-indigo-600 to-indigo-800 py-16 overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute -bottom-10 left-10 w-64 h-64 bg-white rounded-full"></div>
        </div>
        <div class="max-w-4xl mx-auto px-4 text-center relative z-10">
            <h1 class="text-4xl font-extrabold text-white mb-4">تواصل معنا</h1>
            <p class="text-xl text-indigo-100">نحن هنا للإجابة على جميع استفساراتك. لا تتردد في التواصل!</p>
        </div>
    </div>

    <div class="bg-slate-50 py-16 min-h-[60vh]">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">

                <!-- Contact Info Sidebar -->
                <div class="space-y-6">

                    <!-- WhatsApp -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                        <div class="flex items-center gap-4 justify-end">
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900 text-lg mb-1">واتساب</h3>
                                <p class="text-slate-500 text-sm mb-2">للرد السريع على استفساراتك</p>
                                @if(!empty($settings['whatsapp_url']))
                                <a href="{{ $settings['whatsapp_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold px-4 py-2.5 rounded-xl transition text-sm">
                                    <span aria-hidden="true">💬</span>
                                    <span>+{{ $settings['whatsapp_number'] ?? '' }}</span>
                                </a>
                                @elseif(!empty($settings['whatsapp_number']))
                                <span class="text-green-600 font-bold">+{{ $settings['whatsapp_number'] }}</span>
                                @endif
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">💬</div>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                        <div class="flex items-center gap-4 justify-end">
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900 text-lg mb-1">البريد الإلكتروني</h3>
                                <p class="text-slate-500 text-sm mb-2">نرد خلال ٢٤ ساعة</p>
                                <a href="mailto:{{ $settings['site_email'] ?? '' }}" class="text-indigo-600 font-bold hover:underline">{{ $settings['site_email'] ?? '' }}</a>
                            </div>
                            <div class="w-12 h-12 bg-indigo-100 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">📧</div>
                        </div>
                    </div>

                    <!-- Working Hours -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                        <div class="flex items-center gap-4 justify-end">
                            <div class="text-right">
                                <h3 class="font-bold text-slate-900 text-lg mb-1">ساعات العمل</h3>
                                <p class="text-slate-600 text-sm">السبت – الخميس</p>
                                <p class="text-slate-600 text-sm font-bold">١٠ صباحاً – ١٠ مساءً</p>
                            </div>
                            <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">🕙</div>
                        </div>
                    </div>

                    <!-- Social Links -->
                    <div class="bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                        <h3 class="font-bold text-slate-900 text-lg mb-4 text-right">تابعنا على</h3>
                        <div class="flex gap-3 justify-end">
                            @if(!empty($settings['instagram_url']))
                            <a href="{{ $settings['instagram_url'] }}" target="_blank" rel="noopener" class="w-10 h-10 bg-pink-100 hover:bg-pink-500 hover:text-white text-pink-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                            </a>
                            @endif
                            @if(!empty($settings['facebook_url']))
                            <a href="{{ $settings['facebook_url'] }}" target="_blank" rel="noopener" class="w-10 h-10 bg-blue-100 hover:bg-blue-600 hover:text-white text-blue-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                            @endif
                            @if(!empty($settings['youtube_url']))
                            <a href="{{ $settings['youtube_url'] }}" target="_blank" rel="noopener" class="w-10 h-10 bg-red-100 hover:bg-red-600 hover:text-white text-red-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                            </a>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100">
                        <h2 class="text-2xl font-extrabold text-slate-900 mb-2 text-right">أرسل لنا رسالة</h2>
                        <p class="text-slate-500 text-sm mb-8 text-right">سنرد عليك في أقرب وقت ممكن.</p>

                        @if(session('success'))
                        <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl mb-8 text-center font-bold flex items-center gap-2 justify-center">
                            <span>✅</span> {{ session('success') }}
                        </div>
                        @endif

                        <form action="{{ route('contact.submit') }}" method="POST" class="space-y-5">
                            @csrf
                            {{-- Honeypot: hidden from humans, bots fill it in --}}
                            <div style="display:none" aria-hidden="true">
                                <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
                            </div>
                            {{-- Timing token: reject if form is submitted too fast --}}
                            <input type="hidden" name="_loaded_at" value="{{ $formToken ?? now()->timestamp }}">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label for="contact_name" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الاسم الكامل <span class="text-red-500">*</span></label>
                                    <input id="contact_name" type="text" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="اسمك الكامل">
                                    <x-input-error :messages="$errors->get('name')" class="mt-1"/>
                                </div>
                                <div>
                                    <label for="contact_email" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                    <input id="contact_email" type="email" name="email" value="{{ old('email', auth()->user()->email ?? '') }}" required dir="ltr"
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3"
                                        placeholder="email@example.com">
                                    <x-input-error :messages="$errors->get('email')" class="mt-1"/>
                                </div>
                            </div>
                            <div>
                                <label for="contact_subject" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">موضوع الرسالة <span class="text-red-500">*</span></label>
                                <input id="contact_subject" type="text" name="subject" value="{{ old('subject') }}" required
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                    placeholder="استفسار عن طلب، اقتراح...">
                                <x-input-error :messages="$errors->get('subject')" class="mt-1"/>
                            </div>
                            <div>
                                <label for="contact_message" class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الرسالة <span class="text-red-500">*</span></label>
                                <textarea id="contact_message" name="message" rows="5" required
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                    placeholder="اكتب رسالتك هنا...">{{ old('message') }}</textarea>
                                <x-input-error :messages="$errors->get('message')" class="mt-1"/>
                            </div>
                            <div class="pt-2">
                                <button type="submit" class="w-full flex justify-center items-center gap-2 py-4 px-6 rounded-2xl font-extrabold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-lg">
                                    <span>إرسال الرسالة</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                </button>
                            </div>
                        </form>

                        <!-- WhatsApp Quick CTA -->
                        @if(!empty($settings['whatsapp_url']))
                        <div class="mt-6 bg-green-50 border border-green-100 rounded-2xl p-5 flex items-center justify-between">
                            <a href="{{ $settings['whatsapp_url'] . '?text=' . urlencode('مرحبا، أريد الاستفسار عن خدمة HeroKid') }}" target="_blank" rel="noopener"
                                class="flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold px-5 py-2.5 rounded-xl transition text-sm">
                                <span aria-hidden="true">💬</span>
                                تواصل عبر واتساب
                            </a>
                            <p class="text-green-700 font-bold text-sm text-right">للرد الفوري تواصل معنا عبر الواتساب</p>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-front-layout>

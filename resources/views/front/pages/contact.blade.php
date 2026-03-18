<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">تواصل مع فريق HeroKid — دعم العملاء على مدار الساعة</x-slot>
<x-slot name="pageDescription">تواصل مع فريق HeroKid لأي استفسار عن قصص الأطفال المخصصة أو حالة طلبك. نرد خلال ساعات العمل عبر الواتساب أو البريد الإلكتروني.</x-slot>

@push('schema')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "ContactPage",
  "name": "تواصل مع HeroKid",
  "url": "{{ route('contact') }}",
  "description": "صفحة تواصل HeroKid — دعم العملاء لقصص الأطفال المخصصة",
  "mainEntity": {
    "@@type": "LocalBusiness",
    "name": "HeroKid",
    "telephone": "+{{ $settings['whatsapp_number'] ?? '' }}",
    "email": "{{ $settings['site_email'] ?? '' }}",
    "address": {
      "@@type": "PostalAddress",
      "streetAddress": "{{ $settings['address_street'] ?? '' }}",
      "addressLocality": "{{ $settings['address_city'] ?? '' }}",
      "addressCountry": "EG"
    },
    "openingHours": "Sa-Th 09:00-21:00",
    "url": "{{ config('app.url') }}"
  }
}
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
                                <a href="{{ $settings['whatsapp_url'] ?? '#' }}" target="_blank" class="inline-flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold px-4 py-2.5 rounded-xl transition text-sm">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    <span>+{{ $settings['whatsapp_number'] ?? '' }}</span>
                                </a>
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
                            <a href="{{ $settings['instagram_url'] ?? '#' }}" target="_blank" class="w-10 h-10 bg-pink-100 hover:bg-pink-500 hover:text-white text-pink-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                            </a>
                            <a href="{{ $settings['facebook_url'] ?? '#' }}" target="_blank" class="w-10 h-10 bg-blue-100 hover:bg-blue-600 hover:text-white text-blue-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                            <a href="{{ $settings['youtube_url'] ?? '#' }}" target="_blank" class="w-10 h-10 bg-red-100 hover:bg-red-600 hover:text-white text-red-600 rounded-xl flex items-center justify-center transition font-bold text-lg">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                            </a>
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
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الاسم الكامل <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                        placeholder="اسمك الكامل">
                                    <x-input-error :messages="$errors->get('name')" class="mt-1"/>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" value="{{ old('email', auth()->user()->email ?? '') }}" required dir="ltr"
                                        class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3"
                                        placeholder="email@example.com">
                                    <x-input-error :messages="$errors->get('email')" class="mt-1"/>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">موضوع الرسالة <span class="text-red-500">*</span></label>
                                <input type="text" name="subject" value="{{ old('subject') }}" required
                                    class="block w-full rounded-xl border-slate-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-right py-3"
                                    placeholder="استفسار عن طلب، اقتراح...">
                                <x-input-error :messages="$errors->get('subject')" class="mt-1"/>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5 text-right">الرسالة <span class="text-red-500">*</span></label>
                                <textarea name="message" rows="5" required
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
                        <div class="mt-6 bg-green-50 border border-green-100 rounded-2xl p-5 flex items-center justify-between">
                            <a href="{{ ($settings['whatsapp_url'] ?? '#') . '?text=' . urlencode('مرحبا، أريد الاستفسار عن خدمة HeroKid') }}" target="_blank"
                                class="flex items-center gap-2 bg-green-500 hover:bg-green-600 text-white font-bold px-5 py-2.5 rounded-xl transition text-sm">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                تواصل عبر واتساب
                            </a>
                            <p class="text-green-700 font-bold text-sm text-right">للرد الفوري تواصل معنا عبر الواتساب</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-front-layout>

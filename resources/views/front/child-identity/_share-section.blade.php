@if($shareSettings->enabled() && $approvedAttempt?->status === 'succeeded')
    @php
        $shareReady = $share?->status === 'ready' && $share?->share_enabled;
        $shareJson = $shareReady
            ? json_encode($sharePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
    @endphp
    <section class="overflow-hidden rounded-3xl border border-violet-200 bg-white shadow-xl shadow-violet-100/60"
             data-identity-share
             @if(session('child_identity_share_created')) data-share-created="1" @endif
             @if($shareJson) data-share-payload="{{ $shareJson }}" @endif
             @if($share?->status === 'generating') data-share-status-url="{{ route('child-identity.shares.status', [$identity->uuid, $share]) }}" @endif>
        <div class="bg-gradient-to-l from-violet-700 via-indigo-700 to-indigo-800 px-5 py-6 text-white sm:px-8">
            <p class="text-xs font-black text-violet-200">مشاركة آمنة باختيارك</p>
            <h2 class="mt-2 text-2xl font-black">شارك هوية طفلك وخلي أصحابك يجربوا 💜</h2>
            <p class="mt-2 max-w-2xl text-sm leading-7 text-indigo-100">
                شارك الهوية مع العائلة والأصدقاء، وخليهم يصنعوا هوية أطفالهم مجانًا على HeroKid.
            </p>
        </div>

        <div class="p-5 sm:p-8">
            @if(!$share)
                <div class="rounded-2xl border border-violet-100 bg-violet-50/60 p-5 text-center">
                    <p class="text-sm leading-7 text-slate-600">لن ننشر الصورة الخام أو صور الطفل الأصلية. سننشئ بطاقة HeroKid منفصلة بعد موافقتك.</p>
                    <button type="button" data-share-consent-open
                            class="mt-4 w-full rounded-2xl bg-gradient-to-l from-fuchsia-600 to-violet-600 px-6 py-4 font-black text-white shadow-lg shadow-violet-200 sm:w-auto sm:min-w-64">
                        مشاركة الآن
                    </button>
                </div>
            @elseif($share->status === 'generating')
                <div class="flex flex-col items-center rounded-2xl bg-indigo-50 p-7 text-center">
                    <span class="h-11 w-11 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600"></span>
                    <h3 class="mt-4 text-lg font-black text-indigo-950">جاري تجهيز صورة المشاركة...</h3>
                    <p class="mt-2 text-sm leading-7 text-indigo-700">يمكنك اختيار القصة أو متابعة الصفحة؛ ستظهر أدوات المشاركة عند اكتمال البطاقات.</p>
                </div>
            @elseif($share->status === 'failed')
                <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-center">
                    <h3 class="font-black text-red-900">تعذر تجهيز بطاقة المشاركة</h3>
                    <p class="mt-2 text-sm text-red-700">{{ $share->generation_error ?: 'حدث خطأ مؤقت أثناء معالجة الصورة.' }}</p>
                    <form method="POST" action="{{ route('child-identity.shares.regenerate', [$identity->uuid, $share]) }}" class="mt-4">
                        @csrf
                        <button class="rounded-xl bg-red-600 px-5 py-3 font-black text-white">إعادة تجهيز البطاقات</button>
                    </form>
                </div>
            @elseif(!$share->share_enabled || $share->status === 'revoked')
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center">
                    <h3 class="font-black text-amber-900">رابط المشاركة متوقف</h3>
                    <p class="mt-2 text-sm text-amber-700">لا يمكن لأي شخص أو منصة اجتماعية فتح الرابط أو الصورة الآن.</p>
                    <form method="POST" action="{{ route('child-identity.shares.reenable', [$identity->uuid, $share]) }}" class="mt-4">
                        @csrf
                        <button class="rounded-xl bg-amber-600 px-5 py-3 font-black text-white">إعادة تفعيل المشاركة</button>
                    </form>
                </div>
            @elseif($shareReady)
                @if($shareNeedsAttemptUpdate)
                    <form method="POST" action="{{ route('child-identity.shares.update', [$identity->uuid, $share]) }}"
                          class="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        @csrf @method('PATCH')
                        <input type="hidden" name="generation_attempt_id" value="{{ $identity->approved_attempt_id }}">
                        <input type="hidden" name="display_child_first_name" value="{{ $share->display_child_first_name ? 1 : 0 }}">
                        <p class="font-black text-amber-900">اعتمدت هوية أحدث، لكن الرابط ما زال يعرض البطاقة السابقة.</p>
                        <p class="mt-1 text-xs leading-6 text-amber-700">لن نستبدل صورة سبق أن شاركتها بدون قرار واضح منك.</p>
                        <button class="mt-3 rounded-xl bg-amber-600 px-4 py-2 text-sm font-black text-white">تحديث المشاركة إلى الهوية الجديدة</button>
                    </form>
                @endif

                <div class="grid gap-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <img src="{{ $sharePayload['cards']['feed'] }}" alt="بطاقة HeroKid الجاهزة للمشاركة"
                         class="mx-auto aspect-[4/5] max-h-[480px] w-full max-w-sm rounded-2xl bg-slate-100 object-contain shadow-sm">
                    <div>
                        @if($sharePayload['channels']['native'])
                            <button type="button" data-share-action="native"
                                    class="w-full rounded-2xl bg-gradient-to-l from-fuchsia-600 to-violet-600 px-6 py-4 text-base font-black text-white shadow-lg shadow-violet-200">
                                مشاركة الآن
                            </button>
                        @endif

                        <div class="{{ $sharePayload['channels']['native'] ? 'mt-4' : '' }} grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @if($sharePayload['channels']['whatsapp'])
                                <button type="button" data-share-action="whatsapp" class="min-h-12 rounded-xl bg-emerald-600 px-3 py-3 text-sm font-black text-white">واتساب</button>
                            @endif
                            @if($sharePayload['channels']['facebook'])
                                <button type="button" data-share-action="facebook" class="min-h-12 rounded-xl bg-blue-700 px-3 py-3 text-sm font-black text-white">فيسبوك</button>
                            @endif
                            @if($sharePayload['channels']['instagram'])
                                <button type="button" data-share-action="instagram-feed" class="min-h-12 rounded-xl bg-gradient-to-l from-fuchsia-600 to-orange-500 px-3 py-3 text-sm font-black text-white">منشور إنستجرام</button>
                                <button type="button" data-share-action="instagram-story" class="min-h-12 rounded-xl bg-gradient-to-l from-violet-600 to-pink-500 px-3 py-3 text-sm font-black text-white">قصة إنستجرام</button>
                            @endif
                            @if($sharePayload['channels']['copy_link'])
                                <button type="button" data-share-action="copy-link" class="min-h-12 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-3 text-sm font-black text-indigo-700">نسخ الرابط</button>
                            @endif
                            @if($sharePayload['channels']['copy_caption'])
                                <button type="button" data-share-action="copy-caption" class="min-h-12 rounded-xl border border-violet-200 bg-violet-50 px-3 py-3 text-sm font-black text-violet-700">نسخ النص</button>
                            @endif
                            @if($sharePayload['channels']['download'])
                                <button type="button" data-share-action="download-feed" class="min-h-12 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-black text-slate-700">حفظ الصورة</button>
                                <button type="button" data-share-action="download-story" class="min-h-12 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-black text-slate-700">حفظ نسخة القصة</button>
                            @endif
                        </div>

                        <details class="mt-5 rounded-2xl border border-slate-200 p-4">
                            <summary class="cursor-pointer text-sm font-black text-slate-700">إعدادات الرابط العام</summary>
                            <form method="POST" action="{{ route('child-identity.shares.update', [$identity->uuid, $share]) }}" class="mt-4 space-y-3">
                                @csrf @method('PATCH')
                                <input type="hidden" name="generation_attempt_id" value="{{ $identity->approved_attempt_id }}">
                                @if($shareSettings->allowFirstName())
                                    <label class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold text-slate-700">
                                        <input type="checkbox" name="display_child_first_name" value="1" @checked($share->display_child_first_name) class="rounded border-slate-300 text-violet-600">
                                        عرض الاسم الأول فقط على البطاقة
                                    </label>
                                @endif
                                <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">حفظ وإعادة تجهيز البطاقة</button>
                            </form>
                            <form method="POST" action="{{ route('child-identity.shares.revoke', [$identity->uuid, $share]) }}" class="mt-3">
                                @csrf
                                <button class="text-sm font-black text-red-600">إيقاف الرابط العام</button>
                            </form>
                        </details>
                    </div>
                </div>
            @endif
        </div>

        <div data-share-toast role="status" aria-live="polite"
             class="pointer-events-none fixed bottom-5 left-1/2 z-[80] hidden -translate-x-1/2 rounded-2xl bg-slate-950 px-5 py-3 text-center text-sm font-black text-white shadow-2xl"></div>
    </section>

    @if(!$share)
        <dialog data-share-consent-modal class="w-[calc(100%-2rem)] max-w-lg rounded-3xl border-0 p-0 shadow-2xl backdrop:bg-slate-950/60">
            <form method="POST" action="{{ route('child-identity.shares.store', $identity->uuid) }}" class="p-6 sm:p-8" dir="rtl">
                @csrf
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-black text-slate-950">إنشاء رابط عام للمشاركة</h3>
                        <p class="mt-3 text-sm leading-7 text-slate-600">
                            بالمتابعة، سيتم إنشاء صورة تسويقية ورابط عام يحتويان على هوية الطفل المولدة وشعار HeroKid. لن تظهر الصور الأصلية أو رقم الهاتف أو البريد الإلكتروني أو بيانات الطلب. يمكنك إيقاف رابط المشاركة لاحقًا.
                        </p>
                    </div>
                    <button type="button" data-share-consent-close aria-label="إغلاق" class="rounded-full bg-slate-100 px-3 py-2 font-black text-slate-600">×</button>
                </div>
                <label class="mt-5 flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm font-bold leading-7 text-violet-950">
                    <input type="checkbox" name="share_consent" value="1" required class="mt-1 rounded border-violet-300 text-violet-600">
                    أوافق على إنشاء صورة ورابط عام لمشاركة هوية طفلي.
                </label>
                @if($shareSettings->allowFirstName())
                    <label class="mt-3 flex items-start gap-3 rounded-2xl bg-slate-50 p-4 text-sm font-bold text-slate-700">
                        <input type="checkbox" name="display_child_first_name" value="1" class="rounded border-slate-300 text-violet-600">
                        عرض الاسم الأول فقط على بطاقة المشاركة (اختياري)
                    </label>
                @endif
                <button class="mt-5 w-full rounded-2xl bg-violet-600 px-6 py-4 font-black text-white">موافق، جهّز المشاركة</button>
            </form>
        </dialog>
    @endif
@endif

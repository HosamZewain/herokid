@if($shareSettings->enabled() && $approvedAttempt?->status === 'succeeded')
    @php
        $shareReady = $share?->status === 'ready' && $share?->share_enabled;
        $shareJson = $shareReady
            ? json_encode($sharePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
    @endphp
    <section class="rounded-2xl border border-violet-200 bg-violet-50/70 p-4 shadow-sm sm:p-5"
             data-identity-share
             @if(session('child_identity_share_created')) data-share-created="1" @endif
             @if($shareJson) data-share-payload="{{ $shareJson }}" @endif
             @if($share?->status === 'generating') data-share-status-url="{{ route('child-identity.shares.status', [$identity->uuid, $share]) }}" @endif>
        <p class="text-xs font-black text-violet-600">شارك النتيجة</p>
        <h2 class="mt-1 text-xl font-black text-slate-950">خلّي أصحابك يجربوا HeroKid 💜</h2>

        @if(!$share)
            <p class="mt-2 text-sm leading-7 text-slate-600">
                البطاقة المعروضة جاهزة. فعّل رابط المشاركة لتظهر الأزرار فورًا.
            </p>
            <button type="button" data-share-consent-open
                    class="mt-4 w-full rounded-2xl bg-gradient-to-l from-fuchsia-600 to-violet-600 px-6 py-4 font-black text-white shadow-lg shadow-violet-200">
                مشاركة النتيجة
            </button>
        @elseif($share->status === 'generating')
            <div class="mt-4 flex items-center gap-3 rounded-2xl bg-white p-4 text-right">
                <span class="h-8 w-8 shrink-0 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600"></span>
                <div>
                    <h3 class="font-black text-indigo-950">جاري تفعيل المشاركة...</h3>
                    <p class="mt-1 text-xs text-indigo-600">ستظهر الأزرار تلقائيًا خلال لحظات.</p>
                </div>
            </div>
        @elseif($share->status === 'failed')
            <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-center">
                <h3 class="font-black text-red-900">تعذر تفعيل المشاركة</h3>
                <p class="mt-1 text-sm text-red-700">{{ $share->generation_error ?: 'حدث خطأ مؤقت.' }}</p>
                <form method="POST" action="{{ route('child-identity.shares.regenerate', [$identity->uuid, $share]) }}" class="mt-3">
                    @csrf
                    <button class="rounded-xl bg-red-600 px-5 py-3 font-black text-white">إعادة المحاولة</button>
                </form>
            </div>
        @elseif(!$share->share_enabled || $share->status === 'revoked')
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-center">
                <h3 class="font-black text-amber-900">المشاركة متوقفة</h3>
                <form method="POST" action="{{ route('child-identity.shares.reenable', [$identity->uuid, $share]) }}" class="mt-3">
                    @csrf
                    <button class="rounded-xl bg-amber-600 px-5 py-3 font-black text-white">تفعيل المشاركة</button>
                </form>
            </div>
        @elseif($shareReady)
            @if($shareNeedsAttemptUpdate)
                <form method="POST" action="{{ route('child-identity.shares.update', [$identity->uuid, $share]) }}"
                      class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    @csrf @method('PATCH')
                    <input type="hidden" name="generation_attempt_id" value="{{ $identity->approved_attempt_id }}">
                    <input type="hidden" name="display_child_first_name" value="0">
                    <p class="font-black text-amber-900">اعتمدت هوية أحدث من الموجودة في رابط المشاركة.</p>
                    <button class="mt-3 rounded-xl bg-amber-600 px-4 py-2 text-sm font-black text-white">استخدام الهوية الجديدة</button>
                </form>
            @endif

            @if($sharePayload['channels']['native'])
                <button type="button" data-share-action="native"
                        class="mt-4 w-full rounded-2xl bg-gradient-to-l from-fuchsia-600 to-violet-600 px-6 py-4 text-base font-black text-white shadow-lg shadow-violet-200">
                    مشاركة الآن
                </button>
            @endif

            <div class="{{ $sharePayload['channels']['native'] ? 'mt-3' : 'mt-4' }} grid grid-cols-2 gap-2">
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
                    <button type="button" data-share-action="copy-link" class="min-h-12 rounded-xl border border-indigo-200 bg-white px-3 py-3 text-sm font-black text-indigo-700">نسخ الرابط</button>
                @endif
                @if($sharePayload['channels']['copy_caption'])
                    <button type="button" data-share-action="copy-caption" class="min-h-12 rounded-xl border border-violet-200 bg-white px-3 py-3 text-sm font-black text-violet-700">نسخ النص</button>
                @endif
                @if($sharePayload['channels']['download'])
                    <button type="button" data-share-action="download-feed" class="min-h-12 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-black text-slate-700">حفظ الصورة</button>
                    <button type="button" data-share-action="download-story" class="min-h-12 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm font-black text-slate-700">حفظ نسخة القصة</button>
                @endif
            </div>

            <form method="POST" action="{{ route('child-identity.shares.revoke', [$identity->uuid, $share]) }}" class="mt-3 text-center">
                @csrf
                <button class="text-xs font-black text-slate-500 underline underline-offset-4">إيقاف المشاركة</button>
            </form>
        @endif

        <div data-share-toast role="status" aria-live="polite"
             class="pointer-events-none fixed bottom-5 left-1/2 z-[80] hidden -translate-x-1/2 rounded-2xl bg-slate-950 px-5 py-3 text-center text-sm font-black text-white shadow-2xl"></div>
    </section>

    @if(!$share)
        <dialog data-share-consent-modal class="w-[calc(100%-2rem)] max-w-lg rounded-3xl border-0 p-0 shadow-2xl backdrop:bg-slate-950/60">
            <form method="POST" action="{{ route('child-identity.shares.store', $identity->uuid) }}" class="p-6 sm:p-8" dir="rtl">
                @csrf
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-black text-slate-950">تفعيل مشاركة النتيجة</h3>
                        <p class="mt-3 text-sm leading-7 text-slate-600">
                            البطاقة جاهزة بالفعل. بالمتابعة سنفعّل رابطًا عامًا لها فقط، دون نشر صور الطفل الأصلية أو الهاتف أو البريد الإلكتروني أو بيانات الطلب.
                        </p>
                    </div>
                    <button type="button" data-share-consent-close aria-label="إغلاق" class="rounded-full bg-slate-100 px-3 py-2 font-black text-slate-600">×</button>
                </div>
                <label class="mt-5 flex items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm font-bold leading-7 text-violet-950">
                    <input type="checkbox" name="share_consent" value="1" required class="mt-1 rounded border-violet-300 text-violet-600">
                    أوافق على إنشاء رابط عام لمشاركة بطاقة هوية طفلي.
                </label>
                <input type="hidden" name="display_child_first_name" value="0">
                <button class="mt-5 w-full rounded-2xl bg-violet-600 px-6 py-4 font-black text-white">موافق، أظهر أزرار المشاركة</button>
            </form>
        </dialog>
    @endif
@endif

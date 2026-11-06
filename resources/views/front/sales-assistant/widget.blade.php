@php
    $full = $full ?? false;
    $wa = \App\Support\Phone::forWhatsApp($settings['whatsapp_number'] ?? null);
    $whatsapp = $wa ? 'https://wa.me/'.$wa : ($settings['whatsapp_url'] ?? '');
    if (!preg_match('~^https://(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)/~', $whatsapp)) { $whatsapp = ''; }
@endphp
@vite(['resources/css/sales-assistant.css', 'resources/js/sales-assistant.js'])
<div class="hk-sales {{ $full ? 'hk-sales--full' : '' }}" data-sales-assistant data-full="{{ $full ? '1':'0' }}"
    data-state="{{ route('sales.state') }}" data-message="{{ route('sales.message') }}" data-catalog="{{ route('sales.catalog') }}"
    data-event="{{ route('sales.event') }}" data-cart="{{ route('cart.index',['_sales_assistant_embed' => 1]) }}"
    data-item-base="{{ url('/shopping-assistant/item') }}" data-whatsapp="{{ $whatsapp }}" dir="rtl">
    @unless($full)
        <div class="hk-sales-invite" data-invite hidden><span>محتار تختار إيه لطفلك؟</span><button type="button" data-dismiss-invite aria-label="إخفاء الدعوة">×</button></div>
        <button type="button" class="hk-sales-launch" data-open aria-haspopup="dialog" aria-expanded="false" aria-controls="hk-sales-panel">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 2 2.7 6.3L21 11l-6.3 2.7L12 20l-2.7-6.3L3 11l6.3-2.7L12 2Z" fill="currentColor"/><path d="m20 17 .9 2.1L23 20l-2.1.9L20 23l-.9-2.1L17 20l2.1-.9L20 17Z" fill="currentColor"/></svg>
            <span>مساعد HeroKid</span>
        </button>
        <div class="hk-sales-backdrop" data-backdrop hidden></div>
    @endunless
    <section id="hk-sales-panel" class="hk-sales-panel" data-panel @unless($full) hidden role="dialog" aria-modal="true" @endunless aria-labelledby="hk-sales-title">
        <header class="hk-sales-header">
            <div class="hk-sales-avatar" aria-hidden="true">✦</div>
            <div class="hk-sales-heading"><h{{ $full ? '1':'2' }} id="hk-sales-title">مساعد HeroKid</h{{ $full ? '1':'2' }}><p>مساعد ذكي لاختيار حكاية ونشاط لطفلك</p></div>
            <div class="hk-sales-header-actions">
                @unless($full)<a href="{{ route('sales.index') }}" title="فتح في صفحة كاملة" aria-label="فتح في صفحة كاملة">⤢</a><button type="button" data-close aria-label="إغلاق المساعد">×</button>@endunless
            </div>
        </header>
        <nav class="hk-sales-steps" aria-label="خطوات الطلب"><ol>
            @foreach(['اختيار','بيانات وصور','عنوان','مراجعة وتأكيد'] as $step)<li data-step="{{ $loop->index }}" @if($loop->first) aria-current="step" @endif><span>{{ $loop->iteration }}</span>{{ $step }}</li>@endforeach
        </ol></nav>
        <div class="hk-sales-workspace" data-workspace hidden>
            <div class="hk-sales-workbar"><button type="button" data-chat>→ رجوع للمحادثة</button><span data-frame-status>بيانات المنتج والصور</span><button type="button" data-reload-frame>تحديث</button></div>
            <iframe data-purchase-frame title="بيانات المنتج والسلة وإتمام الطلب" referrerpolicy="same-origin"></iframe>
        </div>
        <div class="hk-sales-chat" data-chat-view>
            <div class="hk-sales-messages" data-messages role="log" aria-live="polite" aria-relevant="additions" tabindex="0" aria-label="المحادثة">
                <div class="hk-sales-welcome">
                    <span class="hk-sales-eyebrow">حكاية على مقاس طفلك ✦</span><h2>نلاقي حاجة يحبها،<br>وتضيف له كمان.</h2>
                    <p>أنا مساعد HeroKid الذكي. بتدور على قصة عن موضوع معين، ولا نشاط يناسب طفلك؟</p>
                    <div class="hk-sales-chips">@foreach(['قصة تحببه في الصلاة','بيحب كرة القدم','قصة عن بر الوالدين','نشاط بعيد عن الشاشات'] as $topic)<button type="button" data-topic="{{ $topic }}">{{ $topic }}</button>@endforeach</div>
                    <p class="hk-sales-soft-note">قولي عمره واهتمامه لو حابب. الاسم والصور وبيانات التواصل مكانهم النماذج الخاصة.</p>
                </div>
                <div data-thread></div>
                <div data-browser-results></div>
                <div data-upsells hidden></div>
            </div>
            <p class="hk-sales-notice" data-notice role="status" hidden></p>
            <form class="hk-sales-composer" data-compose>
                <label class="hk-sales-sr" for="hk-sales-message">رسالتك للمساعد، بدون بيانات شخصية</label>
                <textarea id="hk-sales-message" name="message" rows="1" maxlength="1500" placeholder="مثلاً: ابني ٦ سنين وبيحب المغامرات…" required></textarea>
                <button type="submit" data-send aria-label="إرسال الرسالة">↑</button>
            </form>
            <div class="hk-sales-footer"><button type="button" data-browse>تصفح الترشيحات</button><button type="button" data-cart-button>السلة <span data-cart-count>0</span></button>
                @if($whatsapp)<button type="button" data-handoff>كلم الفريق ↗</button>@endif
            </div>
            <details class="hk-sales-privacy"><summary>خصوصية المحادثة</summary><p>ما تكتبش اسم طفلك أو هاتفك أو عنوانك في الشات. بننقّح الرسائل ونرسل نص الاحتياج لـOpenAI للترشيح؛ الصور وحقول التواصل بتفضل في مسار الطلب الخاص. المحادثة بتتحفظ محليًا مشفرة 30 يومًا، مع إحصائيات استخدام بدون الصور. store:false لا يلغي سجلات المراقبة لدى OpenAI.</p></details>
        </div>
    </section>
    <noscript><p>المساعد يحتاج JavaScript. تقدر تكمل من <a href="{{ route('stories.index') }}">القصص</a> و<a href="{{ route('cart.index') }}">السلة</a>.</p></noscript>
</div>

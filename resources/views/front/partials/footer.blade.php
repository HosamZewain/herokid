<footer class="hk-site-footer">
    <div class="hk-footer-inner">
        <div class="hk-footer-brand">
            <a href="{{ route('home') }}"><img src="{{ asset('images/logo-192.png') }}" width="192" height="164" alt="HeroKid Logo" loading="lazy"></a>
            <p>{{ setting('footer_brand_description', 'كل طفل عنده عالم.') }}</p>
        </div>
        <nav aria-labelledby="hk-footer-discover" class="hk-footer-column">
            <h2 id="hk-footer-discover">اكتشف هيروكيد</h2>
            <a href="{{ route('stories.index') }}">قصص</a>
            <a href="{{ route('shop.index', ['type' => 'products']) }}">منتجات مخصصة</a>
            @if(setting('child_identity_enabled', '1') === '1')<a href="{{ route('child-identity.index') }}">اصنع هوية طفلك</a>@endif
            <a href="{{ route('packages') }}">باقات</a>
            <a href="{{ route('shop.index', ['type' => 'activities']) }}">أنشطة وتعلّم</a>
            <a href="{{ route('shop.index') }}">متجر القصص والمنتجات</a>
        </nav>
        <nav aria-labelledby="hk-footer-guide" class="hk-footer-column">
            <h2 id="hk-footer-guide">دليل هيروكيد</h2>
            @foreach(['about' => 'عن HeroKid', 'how-it-works' => 'كيف يعمل؟', 'faq' => 'الأسئلة الشائعة', 'track.index' => 'تتبع الطلب'] as $footerRoute => $footerLabel)
                <a href="{{ route($footerRoute) }}">{{ $footerLabel }}</a>
            @endforeach
        </nav>
        <div class="hk-footer-contact">
            <h2>تواصل معنا</h2>
            <a href="{{ route('contact') }}">محتاج مساعدة؟ كلّمنا</a>
            @if(!empty($settings['site_email']))<a class="hk-footer-address" href="mailto:{{ $settings['site_email'] }}"><bdi dir="ltr">{{ $settings['site_email'] }}</bdi></a>@endif
            @if(!empty($settings['whatsapp_number']))<a class="hk-footer-address" href="tel:{{ $settings['whatsapp_number'] }}"><bdi dir="ltr">{{ $settings['whatsapp_number'] }}</bdi></a>@endif
            <div class="hk-footer-social" aria-label="تابع هيروكيد">
                @foreach(['facebook_url' => 'Facebook', 'instagram_url' => 'Instagram', 'youtube_url' => 'YouTube', 'whatsapp_url' => 'WhatsApp'] as $contactKey => $contactLabel)
                    @if(!empty($settings[$contactKey]))
                        <a href="{{ $settings[$contactKey] }}" target="_blank" rel="noopener noreferrer" aria-label="HeroKid — {{ $contactLabel }}"><span dir="ltr">{{ $contactLabel }}</span></a>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    <div class="hk-footer-bottom">
        <span>HeroKid © {{ date('Y') }} جميع الحقوق محفوظة.</span>
        <nav aria-label="السياسات" class="hk-footer-policies">
            <a href="{{ route('privacy') }}">سياسة الخصوصية</a>
            <a href="{{ route('terms') }}">الشروط والأحكام</a>
        </nav>
        <span>صُنع بحب في مصر</span>
    </div>
</footer>

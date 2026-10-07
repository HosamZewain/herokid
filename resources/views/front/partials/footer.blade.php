<footer class="hk-site-footer">
    <div class="hk-footer-inner">
        <div class="hk-footer-brand">
            <a href="{{ route('home') }}"><img src="{{ asset('images/logo-192.png') }}" width="192" height="164" alt="HeroKid Logo" loading="lazy"></a>
            <p>{{ setting('footer_brand_description', 'كل طفل عنده عالم.') }}</p>
        </div>
        <nav aria-label="روابط الأقسام" class="hk-footer-column">
            <a href="{{ route('stories.index') }}">قصص</a>
            <a href="{{ route('shop.index', ['type' => 'products']) }}">منتجات مخصصة</a>
            @if(setting('child_identity_enabled', '1') === '1')<a href="{{ route('child-identity.index') }}">اصنع هوية طفلك</a>@endif
        </nav>
        <nav aria-label="روابط الأنشطة والباقات" class="hk-footer-column">
            <a href="{{ route('packages') }}">باقات</a>
            <a href="{{ route('shop.index', ['type' => 'activities']) }}">أنشطة وتعلّم</a>
            <a href="{{ route('shop.index') }}">متجر القصص والمنتجات</a>
        </nav>
        <nav aria-label="المساعدة والسياسات" class="hk-footer-column">
            @foreach(['about' => 'عن HeroKid', 'how-it-works' => 'كيف يعمل؟', 'faq' => 'الأسئلة الشائعة', 'contact' => 'تواصل معانا', 'privacy' => 'سياسة الخصوصية', 'terms' => 'الشروط والأحكام', 'track.index' => 'تتبع الطلب'] as $footerRoute => $footerLabel)
                <a href="{{ route($footerRoute) }}">{{ $footerLabel }}</a>
            @endforeach
        </nav>
        <div class="hk-footer-contact">
            @foreach(['facebook_url' => 'Facebook', 'instagram_url' => 'Instagram', 'youtube_url' => 'YouTube', 'whatsapp_url' => 'WhatsApp'] as $contactKey => $contactLabel)
                @if(!empty($settings[$contactKey]))
                    <a href="{{ $settings[$contactKey] }}" target="_blank" rel="noopener noreferrer">{{ $contactLabel }}</a>
                @endif
            @endforeach
            @if(!empty($settings['site_email']))<a href="mailto:{{ $settings['site_email'] }}">تواصل معنا بالبريد</a>@endif
            @if(!empty($settings['whatsapp_number']))<a href="tel:{{ $settings['whatsapp_number'] }}" dir="ltr">{{ $settings['whatsapp_number'] }}</a>@endif
        </div>
    </div>
    <div class="hk-footer-bottom"><span>HeroKid © {{ date('Y') }} جميع الحقوق محفوظة.</span><span>صُنع بحب في مصر</span></div>
</footer>

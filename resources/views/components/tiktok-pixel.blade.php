@php
    $pixelId = trim((string) (\App\Support\RequestSettings::all()['tiktok_pixel_id'] ?? config('services.tiktok_pixel.id', '')));
    $pixelEnabled = preg_match('/^[A-Z0-9]{10,32}$/', $pixelId) && \App\Services\Analytics\StorefrontTraffic::publicCommerce(request());
@endphp
@if($pixelEnabled)
    <!-- TikTok Pixel: public commerce only; no identify or customer/child payloads. -->
    <script>
        !function (w, d, t) {
            w.TiktokAnalyticsObject=t;
            var ttq=w[t]=w[t]||[];
            ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"];
            ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
            for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
            ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};
            ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};n=d.createElement("script");n.type="text/javascript";n.async=true;n.src=r+"?sdkid="+e+"&lib="+t;var s=d.getElementsByTagName("script")[0];s.parentNode.insertBefore(n,s)};
            ttq.load(@json($pixelId));
            ttq.page();
            @if(request()->routeIs('shop.product.show', 'shop.package.show', 'stories.show'))
                ttq.track('ViewContent');
            @endif
            @if(session()->pull('tiktok.cart_added', false))
                ttq.track('AddToCart');
            @endif
            @if(request()->routeIs('cart.index') && count(session('cart.items', [])) > 0)
                ttq.track('InitiateCheckout');
            @endif
        }(window, document, 'ttq');
    </script>
@endif

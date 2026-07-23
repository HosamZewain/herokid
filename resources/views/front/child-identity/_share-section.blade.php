@if($shareSettings->enabled() && $approvedAttempt?->status === 'succeeded')
    @php
        $shareReady = $share?->status === 'ready' && $share?->share_enabled;
        $shareJson = $shareReady
            ? json_encode($sharePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        $quickActions = [
            'whatsapp' => ['واتساب', 'bg-emerald-600 text-white'],
            'facebook' => ['فيسبوك', 'bg-blue-700 text-white'],
            'download' => ['تحميل', 'border border-slate-300 bg-white text-slate-700'],
        ];
    @endphp

    <p class="mt-4 text-center text-sm font-black text-slate-700">
        شارك صورة هوية طفلك مع عائلتك واصدقائك
    </p>
    <div class="mx-auto mt-2 flex w-full max-w-xl flex-wrap items-center justify-center gap-2"
         data-identity-share
         @if($shareJson) data-share-payload="{{ $shareJson }}" @endif>
        @if($shareReady)
            @if($shareSettings->channelEnabled('whatsapp'))
                <button type="button" data-share-action="whatsapp"
                        class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">
                    <img src="{{ asset('images/icons/whatsapp-white.svg') }}" alt="" class="h-4 w-4" aria-hidden="true">
                    واتساب
                </button>
            @endif
            @if($shareSettings->channelEnabled('facebook'))
                <button type="button" data-share-action="facebook"
                        class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-700 px-4 py-2 text-sm font-black text-white">
                    <img src="{{ asset('images/icons/facebook-white.svg') }}" alt="" class="h-4 w-4" aria-hidden="true">
                    فيسبوك
                </button>
            @endif
            @if($shareSettings->channelEnabled('download'))
                <button type="button" data-share-action="download-feed"
                        class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-black text-slate-700">
                    تحميل
                </button>
            @endif
        @else
            @foreach($quickActions as $action => [$label, $classes])
                @if($action === 'download' || $shareSettings->channelEnabled($action))
                    <form method="POST"
                          action="{{ route('child-identity.shares.store', $identity->uuid) }}"
                          @if($action !== 'download') target="_blank" @endif>
                        @csrf
                        <input type="hidden" name="share_action" value="{{ $action }}">
                        <button class="inline-flex min-h-10 items-center gap-2 rounded-xl px-4 py-2 text-sm font-black {{ $classes }}">
                            @if($action === 'whatsapp')
                                <img src="{{ asset('images/icons/whatsapp-white.svg') }}" alt="" class="h-4 w-4" aria-hidden="true">
                            @elseif($action === 'facebook')
                                <img src="{{ asset('images/icons/facebook-white.svg') }}" alt="" class="h-4 w-4" aria-hidden="true">
                            @endif
                            {{ $label }}
                        </button>
                    </form>
                @endif
            @endforeach
        @endif

        <div data-share-toast role="status" aria-live="polite"
             class="pointer-events-none fixed bottom-5 left-1/2 z-[80] hidden -translate-x-1/2 rounded-2xl bg-slate-950 px-5 py-3 text-center text-sm font-black text-white shadow-2xl"></div>
    </div>
@endif

@php
    $messages = collect($whatsappMessages ?? []);
    $compact ??= false;
    $labelledCompact ??= false;
@endphp

@if($messages->isNotEmpty())
    @if($compact)
        <details class="relative" data-whatsapp-messages>
            <summary title="رسائل واتساب" aria-label="رسائل واتساب" class="{{ $labelledCompact ? 'inline-flex min-h-11 items-center gap-2 rounded-xl px-4 py-2.5 text-sm' : 'grid h-8 w-8 place-items-center rounded-md' }} cursor-pointer list-none bg-green-50 font-black text-green-700 hover:bg-green-100 [&::-webkit-details-marker]:hidden">
                <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.52 3.48A11.91 11.91 0 0012.06 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.59 5.95L.06 24l6.29-1.65a11.9 11.9 0 005.7 1.45h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.22-6.16-3.44-8.42zm-8.46 18.31h-.01a9.88 9.88 0 01-5.04-1.38l-.36-.21-3.73.98 1-3.64-.24-.37a9.86 9.86 0 01-1.51-5.27c0-5.45 4.44-9.89 9.9-9.89a9.82 9.82 0 017 2.9 9.82 9.82 0 012.9 7c-.01 5.45-4.45 9.88-9.9 9.88zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47a8.94 8.94 0 01-1.65-2.05c-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.08-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.3 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.3.18-1.42-.08-.12-.27-.2-.57-.35z"/></svg>
                @if($labelledCompact)<span>مراسلة واتساب</span>@endif
            </summary>
            <div class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-2xl border border-emerald-100 bg-white p-2 text-right shadow-xl">
                @foreach($messages as $message)
                    <a href="{{ $message['url'] }}" target="_blank" rel="noopener" class="block rounded-xl px-3 py-2.5 text-xs font-black text-emerald-800 hover:bg-emerald-50">{{ $message['title'] }}</a>
                @endforeach
            </div>
        </details>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach($messages as $message)
                <a href="{{ $message['url'] }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-center text-sm font-black text-white hover:bg-green-700">
                    <svg class="h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M20.52 3.48A11.91 11.91 0 0012.06 0C5.5 0 .16 5.34.16 11.9c0 2.1.55 4.15 1.59 5.95L.06 24l6.29-1.65a11.9 11.9 0 005.7 1.45h.01c6.56 0 11.9-5.34 11.9-11.9 0-3.18-1.22-6.16-3.44-8.42zm-8.46 18.31h-.01a9.88 9.88 0 01-5.04-1.38l-.36-.21-3.73.98 1-3.64-.24-.37a9.86 9.86 0 01-1.51-5.27c0-5.45 4.44-9.89 9.9-9.89a9.82 9.82 0 017 2.9 9.82 9.82 0 012.9 7c-.01 5.45-4.45 9.88-9.9 9.88zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47a8.94 8.94 0 01-1.65-2.05c-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.08-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.3 1.27.49 1.7.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.3.18-1.42-.08-.12-.27-.2-.57-.35z"/></svg>
                    {{ $message['title'] }}
                </a>
            @endforeach
        </div>
    @endif
@endif

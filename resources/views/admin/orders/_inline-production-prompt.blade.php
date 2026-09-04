@php
    $promptDomId = $promptDomId ?? 'production-prompt-'.uniqid();
    $promptType = $promptType ?? 'product_production';
    $orderItemId = $orderItemId ?? null;
@endphp

<div class="mt-4 rounded-2xl border border-fuchsia-200 bg-fuchsia-50/60 p-3" data-inline-production-prompt>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <button
            type="button"
            data-copy-inline-production-prompt="{{ $promptDomId }}"
            data-prompt-type="{{ $promptType }}"
            @if($orderItemId) data-order-item-id="{{ $orderItemId }}" @endif
            class="inline-flex min-h-10 items-center justify-center rounded-xl bg-fuchsia-600 px-4 py-2 text-xs font-black text-white transition hover:bg-fuchsia-700 active:scale-95 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
        >
            نسخ البرومبت
        </button>
        <div class="text-right">
            <p class="text-xs font-black text-fuchsia-900">{{ $promptTitle }}</p>
            <p class="mt-0.5 text-[10px] font-bold text-fuchsia-600">النص مخفي افتراضيًا لتبقى صفحة الطلب واضحة.</p>
        </div>
    </div>

    <details class="mt-3 rounded-xl border border-fuchsia-100 bg-white">
        <summary class="cursor-pointer list-none px-3 py-2 text-xs font-black text-fuchsia-700 [&::-webkit-details-marker]:hidden">عرض نص البرومبت</summary>
        <div class="border-t border-fuchsia-100 p-3">
            <textarea id="{{ $promptDomId }}" rows="16" readonly dir="ltr" spellcheck="false" class="block w-full rounded-xl border-gray-200 bg-slate-50 text-left font-mono text-xs leading-6 text-slate-800">{{ $promptText }}</textarea>
        </div>
    </details>
    <p class="mt-2 hidden rounded-lg bg-emerald-100 px-3 py-2 text-xs font-black text-emerald-700" data-inline-production-prompt-message role="status">تم نسخ البرومبت بنجاح</p>
</div>

@once
    @push('scripts')
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-copy-inline-production-prompt]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var textarea = document.getElementById(button.dataset.copyInlineProductionPrompt);
                    var card = button.closest('[data-inline-production-prompt]');
                    var message = card?.querySelector('[data-inline-production-prompt-message]');
                    if (!textarea) return;

                    function copied() {
                        message?.classList.remove('hidden');
                        window.setTimeout(function () { message?.classList.add('hidden'); }, 1800);
                        window.HeroKidOrderActivity?.recordPromptCopy(button.dataset.promptType, button.dataset.orderItemId || null);
                    }

                    function fallbackCopy() {
                        textarea.focus();
                        textarea.select();
                        document.execCommand('copy');
                        window.getSelection()?.removeAllRanges();
                    }

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(textarea.value).then(copied).catch(function () {
                            fallbackCopy();
                            copied();
                        });
                        return;
                    }

                    fallbackCopy();
                    copied();
                });
            });
        });
        </script>
    @endpush
@endonce

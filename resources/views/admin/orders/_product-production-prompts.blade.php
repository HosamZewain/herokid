@if(($productProductionPrompts ?? collect())->isNotEmpty())
    <section class="space-y-4" data-product-production-prompts>
        <div class="flex items-center justify-between gap-3">
            <span class="rounded-full bg-fuchsia-50 px-3 py-1.5 text-xs font-black text-fuchsia-700">{{ $productProductionPrompts->count() }} برومبت</span>
            <h3 class="text-xl font-black text-gray-900">برومبت إنتاج المنتج</h3>
        </div>

        @foreach($productProductionPrompts as $productPrompt)
            <div class="rounded-2xl border border-fuchsia-100 bg-white p-6 shadow-sm" data-product-production-prompt-card>
                <div class="mb-4 flex flex-col gap-3 border-b pb-3 md:flex-row md:items-center md:justify-between">
                    <div class="text-right">
                        <h4 class="text-lg font-bold">{{ $productPrompt['item']->title }}</h4>
                        <p class="mt-1 text-xs font-bold text-gray-500">Product Production Prompt</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full border px-3 py-1.5 text-xs font-black {{ $productPrompt['uses_live_template'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
                            {{ $productPrompt['uses_live_template'] ? 'قالب المنتج الحالي — يتحدّث تلقائيًا' : 'نسخة تاريخية احتياطية' }}
                        </span>
                        <button
                            type="button"
                            data-copy-product-production-prompt
                            class="inline-flex items-center justify-center rounded-xl bg-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-fuchsia-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
                        >
                            نسخ برومبت المنتج
                        </button>
                    </div>
                </div>

                <textarea
                    rows="26"
                    readonly
                    dir="ltr"
                    spellcheck="false"
                    data-product-production-prompt
                    class="block w-full rounded-xl border-gray-300 bg-fuchsia-50/30 text-left font-mono text-sm leading-6 text-slate-800 shadow-sm"
                >{{ $productPrompt['prompt'] }}</textarea>
                <div data-product-production-prompt-message class="mt-3 hidden rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-right text-sm font-bold text-green-700" role="status" aria-live="polite">
                    تم نسخ برومبت إنتاج المنتج بنجاح
                </div>
            </div>
        @endforeach
    </section>

    @once
        @push('scripts')
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-product-production-prompt-card]').forEach(function (card) {
                var button = card.querySelector('[data-copy-product-production-prompt]');
                var textarea = card.querySelector('[data-product-production-prompt]');
                var message = card.querySelector('[data-product-production-prompt-message]');

                if (!button || !textarea || !message) return;

                function showMessage() {
                    message.classList.remove('hidden');
                    window.setTimeout(function () { message.classList.add('hidden'); }, 3000);
                }

                function fallbackCopy() {
                    textarea.focus();
                    textarea.select();
                    document.execCommand('copy');
                    window.getSelection().removeAllRanges();
                }

                button.addEventListener('click', function () {
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(textarea.value).then(showMessage).catch(function () {
                            fallbackCopy();
                            showMessage();
                        });
                        return;
                    }

                    fallbackCopy();
                    showMessage();
                });
            });
        });
        </script>
        @endpush
    @endonce
@endif

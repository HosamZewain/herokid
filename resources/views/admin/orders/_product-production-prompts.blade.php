@if(($productProductionPrompts ?? collect())->isNotEmpty())
    @php($collapsed ??= false)
    @if($collapsed)
        <section class="rounded-3xl border border-fuchsia-100 bg-white shadow-sm" data-product-production-prompts>
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div class="flex flex-wrap gap-2">
                    @foreach($productProductionPrompts as $productPrompt)
                        <button
                            type="button"
                            data-copy-product-production-prompt-target="product-production-prompt-{{ $productPrompt['item']->id }}"
                            data-order-item-id="{{ $productPrompt['item']->id }}"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl bg-fuchsia-600 px-3 py-2 text-xs font-black text-white transition hover:bg-fuchsia-700 active:scale-95 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
                        >
                            نسخ برومبت {{ $loop->iteration }}
                        </button>
                    @endforeach
                </div>
                <div class="text-right">
                    <h3 class="text-base font-black text-gray-900">برومبت إنتاج المنتج</h3>
                    <p class="mt-1 text-xs font-bold text-gray-500">النسخ متاح مباشرة، بينما النصوص مخفية لتوفير المساحة.</p>
                </div>
            </div>
            <div data-product-production-prompt-quick-message class="mx-5 mb-3 hidden rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-right text-xs font-black text-green-700 sm:mx-6" role="status" aria-live="polite">تم نسخ برومبت إنتاج المنتج بنجاح</div>
            <details class="group border-t border-fuchsia-100">
                <summary class="cursor-pointer list-none px-5 py-3 text-xs font-black text-fuchsia-700 sm:px-6 [&::-webkit-details-marker]:hidden">عرض نصوص البرومبتات</summary>
                <div class="space-y-4 border-t border-fuchsia-100 p-4 sm:p-6">
    @else
    <section class="space-y-4" data-product-production-prompts>
        <div class="flex items-center justify-between gap-3">
            <span class="rounded-full bg-fuchsia-50 px-3 py-1.5 text-xs font-black text-fuchsia-700">{{ $productProductionPrompts->count() }} برومبت</span>
            <h3 class="text-xl font-black text-gray-900">برومبت إنتاج المنتج</h3>
        </div>
    @endif

        @foreach($productProductionPrompts as $productPrompt)
            <div class="rounded-2xl border border-fuchsia-100 bg-white p-4 shadow-sm sm:p-6" data-product-production-prompt-card>
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
                            data-order-item-id="{{ $productPrompt['item']->id }}"
                            class="inline-flex items-center justify-center rounded-xl bg-fuchsia-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-fuchsia-700 active:scale-95 focus:outline-none focus:ring-2 focus:ring-fuchsia-500 focus:ring-offset-2"
                        >
                            نسخ برومبت المنتج
                        </button>
                    </div>
                </div>

                <textarea
                    id="product-production-prompt-{{ $productPrompt['item']->id }}"
                    rows="{{ $collapsed ? 18 : 26 }}"
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
    @if($collapsed)
                </div>
            </details>
        </section>
    @else
        </section>
    @endif

    @once
        @push('scripts')
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function copyPrompt(textarea, message, orderItemId) {
                function showMessage() {
                    if (!message) return;
                    message.classList.remove('hidden');
                    window.setTimeout(function () { message.classList.add('hidden'); }, 3000);
                }

                function copied() {
                    showMessage();
                    window.HeroKidOrderActivity?.recordPromptCopy('product_production', orderItemId);
                }

                function fallbackCopy() {
                    textarea.focus();
                    textarea.select();
                    document.execCommand('copy');
                    window.getSelection().removeAllRanges();
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
            }

            function flashCopyButton(button) {
                var originalText = button.textContent;
                button.textContent = 'تم النسخ ✓';
                button.classList.remove('bg-fuchsia-600');
                button.classList.add('bg-emerald-600');
                window.setTimeout(function () {
                    button.textContent = originalText;
                    button.classList.remove('bg-emerald-600');
                    button.classList.add('bg-fuchsia-600');
                }, 1800);
            }

            document.querySelectorAll('[data-product-production-prompt-card]').forEach(function (card) {
                var button = card.querySelector('[data-copy-product-production-prompt]');
                var textarea = card.querySelector('[data-product-production-prompt]');
                var message = card.querySelector('[data-product-production-prompt-message]');

                if (!button || !textarea || !message) return;

                button.addEventListener('click', function () {
                    copyPrompt(textarea, message, button.dataset.orderItemId);
                    flashCopyButton(button);
                });
            });

            document.querySelectorAll('[data-copy-product-production-prompt-target]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var textarea = document.getElementById(button.dataset.copyProductProductionPromptTarget);
                    if (!textarea) return;
                    copyPrompt(textarea, button.closest('[data-product-production-prompts]')?.querySelector('[data-product-production-prompt-quick-message]'), button.dataset.orderItemId);
                    flashCopyButton(button);
                });
            });
        });
        </script>
        @endpush
    @endonce
@endif

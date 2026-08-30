@php
    $activityEvents = data_get($orderActivity ?? [], 'events', collect());
    $activityCount = (int) data_get($orderActivity ?? [], 'count', 0);
    $activityLogUrl = isset($activityTargetOrder)
        ? route('admin.orders.activity.prompt-copied', $activityTargetOrder)
        : null;
    $categoryClasses = [
        'created' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        'status' => 'bg-blue-100 text-blue-700 ring-blue-200',
        'attachment' => 'bg-cyan-100 text-cyan-700 ring-cyan-200',
        'prompt' => 'bg-fuchsia-100 text-fuchsia-700 ring-fuchsia-200',
        'note' => 'bg-amber-100 text-amber-700 ring-amber-200',
        'assignment' => 'bg-violet-100 text-violet-700 ring-violet-200',
        'deleted' => 'bg-rose-100 text-rose-700 ring-rose-200',
        'updated' => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];
    $categoryIcons = [
        'created' => '✓',
        'status' => '↻',
        'attachment' => '↥',
        'prompt' => '⧉',
        'note' => '✎',
        'assignment' => '♟',
        'deleted' => '×',
        'updated' => '•',
    ];
@endphp

<div
    class="pointer-events-none fixed inset-0 z-[70] hidden"
    data-order-activity-root
    data-prompt-copy-log-url="{{ $activityLogUrl }}"
    aria-hidden="true"
>
    <button
        type="button"
        class="absolute inset-0 bg-slate-950/45 opacity-0 backdrop-blur-[1px] transition-opacity duration-200"
        aria-label="إغلاق سجل الطلب"
        data-order-activity-overlay
    ></button>

    <aside
        id="order-activity-drawer"
        class="absolute inset-y-0 left-0 flex w-full max-w-md -translate-x-full flex-col bg-white text-right shadow-2xl transition-transform duration-200 ease-out sm:w-[28rem]"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-activity-title"
        tabindex="-1"
        data-order-activity-drawer
    >
        <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-4 sm:px-5">
            <button
                type="button"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                aria-label="إغلاق سجل الطلب"
                data-order-activity-close
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div>
                <div class="flex items-center justify-end gap-2">
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-black text-indigo-700">{{ $activityCount }} حدث</span>
                    <h2 id="order-activity-title" class="text-lg font-black text-gray-950">سجل الطلب</h2>
                </div>
                <p class="mt-1 text-xs font-bold text-gray-500">أحدث العمليات أولًا · السجل موحّد لكل عناصر عملية الشراء</p>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-5" data-order-activity-list>
            @forelse($activityEvents as $event)
                @php($displayDate = \App\Support\OrderDateTime::display($event['created_at']))
                <article class="relative flex gap-3 pb-6 last:pb-0">
                    @unless($loop->last)
                        <span class="absolute bottom-0 right-[1.15rem] top-10 w-px bg-gray-200" aria-hidden="true"></span>
                    @endunless
                    <div class="min-w-0 flex-1 rounded-2xl border border-gray-100 bg-gray-50/70 p-3.5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="text-[10px] font-bold text-gray-400" dir="ltr">
                                {{ $displayDate?->format('d/m/Y') }}
                                <span class="mx-1">•</span>
                                {{ $displayDate?->format('h:i A') }}
                            </div>
                            <p class="min-w-0 flex-1 text-sm font-black leading-6 text-gray-900">{{ $event['description'] }}</p>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center justify-end gap-2 text-[11px] font-bold">
                            @if($event['order_reference'])
                                <span class="rounded-lg bg-white px-2 py-1 font-mono text-gray-500" dir="ltr">{{ $event['order_reference'] }}</span>
                            @endif
                            <span class="text-gray-500">بواسطة {{ $event['actor'] }}</span>
                        </div>
                        @if($event['details']->isNotEmpty())
                            <details class="mt-3 rounded-xl border border-gray-200 bg-white">
                                <summary class="cursor-pointer list-none px-3 py-2 text-[11px] font-black text-indigo-700 [&::-webkit-details-marker]:hidden">عرض التفاصيل</summary>
                                <dl class="space-y-2 border-t border-gray-100 px-3 py-2.5">
                                    @foreach($event['details'] as $detail)
                                        <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-2 text-[11px] leading-5">
                                            <dt class="font-black text-gray-500">{{ $detail['label'] }}</dt>
                                            <dd class="break-words font-bold text-gray-800">{{ $detail['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </details>
                        @endif
                    </div>
                    <span class="relative z-10 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-black ring-1 {{ $categoryClasses[$event['category']] ?? $categoryClasses['updated'] }}" aria-hidden="true">
                        {{ $categoryIcons[$event['category']] ?? $categoryIcons['updated'] }}
                    </span>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-5 py-10 text-center">
                    <p class="text-sm font-black text-gray-700">لا توجد عمليات مسجلة بعد.</p>
                </div>
            @endforelse
        </div>
    </aside>
</div>

@once
    @push('scripts')
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var root = document.querySelector('[data-order-activity-root]');
            if (!root) return;

            var drawer = root.querySelector('[data-order-activity-drawer]');
            var overlay = root.querySelector('[data-order-activity-overlay]');
            var toggle = document.querySelector('[data-order-activity-toggle]');
            var closeButton = root.querySelector('[data-order-activity-close]');
            var previouslyFocused = null;

            function openDrawer() {
                previouslyFocused = document.activeElement;
                root.classList.remove('hidden');
                root.classList.add('pointer-events-auto');
                root.setAttribute('aria-hidden', 'false');
                toggle?.setAttribute('aria-expanded', 'true');
                document.body.classList.add('overflow-hidden');
                window.requestAnimationFrame(function () {
                    drawer.classList.remove('-translate-x-full');
                    overlay.classList.remove('opacity-0');
                    drawer.focus();
                });
            }

            function closeDrawer() {
                drawer.classList.add('-translate-x-full');
                overlay.classList.add('opacity-0');
                toggle?.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('overflow-hidden');
                window.setTimeout(function () {
                    root.classList.add('hidden');
                    root.classList.remove('pointer-events-auto');
                    root.setAttribute('aria-hidden', 'true');
                    previouslyFocused?.focus();
                }, 200);
            }

            toggle?.addEventListener('click', openDrawer);
            overlay?.addEventListener('click', closeDrawer);
            closeButton?.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && root.getAttribute('aria-hidden') === 'false') closeDrawer();
            });

            window.HeroKidOrderActivity = {
                recordPromptCopy: function (promptType, orderItemId) {
                    var url = root.dataset.promptCopyLogUrl;
                    var csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                    if (!url || !csrf) return;

                    window.fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive: true,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            prompt_type: promptType,
                            order_item_id: orderItemId || null,
                        }),
                    }).catch(function () {
                        // Copying must remain available even if audit delivery is temporarily unavailable.
                    });
                },
            };
        });
        </script>
    @endpush
@endonce

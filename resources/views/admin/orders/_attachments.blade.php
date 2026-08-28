@php
    $attachmentOrders = collect($attachmentOrders ?? [$attachmentTarget ?? $order ?? null])->filter();
    $attachmentTarget ??= $attachmentOrders->first();
    $orderAttachments = $attachmentOrders
        ->flatMap(fn ($attachmentOrder) => $attachmentOrder->attachments->map(fn ($attachment) => [
            'attachment' => $attachment,
            'order' => $attachmentOrder,
        ]))
        ->sortByDesc(fn ($entry) => $entry['attachment']->created_at)
        ->values();
@endphp

@if($attachmentTarget)
    <section class="rounded-3xl border border-sky-100 bg-white p-5 text-right shadow-sm sm:p-6" aria-labelledby="order-attachments-heading">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 id="order-attachments-heading" class="text-lg font-black text-gray-900">📎 مرفقات الطلب</h3>
                <p class="mt-1 text-xs font-bold leading-6 text-gray-500">ارفع صورًا أو PDF بشكل خاص. الصلاحية الافتراضية 30 يومًا، ثم يُحذف الملف تلقائيًا.</p>
            </div>
            @if($orderAttachments->isNotEmpty())
                <span class="w-fit rounded-full bg-sky-50 px-3 py-1.5 text-xs font-black text-sky-700">{{ $orderAttachments->count() }} مرفق</span>
            @endif
        </div>

        @can('orders.update')
            <form method="POST" action="{{ route('admin.orders.attachments.store', $attachmentTarget) }}" enctype="multipart/form-data" class="mt-5 grid gap-3 rounded-2xl border border-dashed border-sky-200 bg-sky-50/60 p-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:items-end" data-order-attachment-form>
                @csrf
                <div>
                    <label for="order-attachments-files-{{ $attachmentTarget->id }}" class="mb-1.5 block text-xs font-black text-gray-700">الملفات</label>
                    <input id="order-attachments-files-{{ $attachmentTarget->id }}" name="attachments[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif" multiple required class="block w-full rounded-xl border border-sky-200 bg-white text-sm file:ml-3 file:border-0 file:bg-sky-600 file:px-4 file:py-2.5 file:font-black file:text-white" data-order-attachment-input>
                    <p class="mt-1 text-[11px] font-bold text-gray-400">PDF، JPG، PNG، WEBP، HEIC · حتى 50 ميجا للملف · تُحذف بعد 30 يومًا</p>
                    <p class="mt-2 hidden text-xs font-black text-sky-700" data-order-attachment-selection aria-live="polite"></p>
                    <x-input-error :messages="$errors->get('attachments')" class="mt-2" />
                    <x-input-error :messages="$errors->get('attachments.*')" class="mt-1" />
                </div>
                <div>
                    <label for="order-attachments-note-{{ $attachmentTarget->id }}" class="mb-1.5 block text-xs font-black text-gray-700">ملاحظة (اختياري)</label>
                    <input id="order-attachments-note-{{ $attachmentTarget->id }}" name="note" value="{{ old('note') }}" maxlength="1000" class="w-full rounded-xl border-sky-200 bg-white text-sm" placeholder="مثال: الملف المعتمد للطباعة">
                </div>
                <button type="submit" class="min-h-12 w-full rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 lg:col-span-2" data-order-attachment-submit>
                    ⬆️ رفع المرفقات الآن
                </button>
            </form>

            @once
                @push('scripts')
                    <script>
                        document.addEventListener('change', (event) => {
                            const input = event.target.closest('[data-order-attachment-input]');
                            if (!input) return;

                            const form = input.closest('[data-order-attachment-form]');
                            const selection = form?.querySelector('[data-order-attachment-selection]');
                            if (!selection) return;

                            const count = input.files?.length ?? 0;
                            selection.textContent = count === 1
                                ? `تم اختيار الملف: ${input.files[0].name}`
                                : count > 1
                                    ? `تم اختيار ${count} ملفات — اضغط «رفع المرفقات الآن» لإكمال الرفع.`
                                    : '';
                            selection.classList.toggle('hidden', count === 0);
                        });
                    </script>
                @endpush
            @endonce
        @endcan

        <div class="mt-5 grid gap-3 md:grid-cols-2">
            @forelse($orderAttachments as $entry)
                @php
                    $attachment = $entry['attachment'];
                    $attachmentOrder = $entry['order'];
                    $expired = $attachment->isExpired();
                    $remainingDays = $expired ? 0 : max(1, (int) ceil(now()->diffInHours($attachment->expires_at) / 24));
                @endphp
                <article class="rounded-2xl border p-4 {{ $expired ? 'border-red-100 bg-red-50/60' : 'border-gray-100 bg-gray-50' }}">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-xl shadow-sm" aria-hidden="true">{{ $attachment->icon }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-black text-gray-900" title="{{ $attachment->original_name }}">{{ $attachment->original_name }}</p>
                            <div class="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-[11px] font-bold text-gray-500">
                                <span>{{ $attachment->human_size }}</span>
                                <span dir="ltr">{{ $attachmentOrder->order_number }}</span>
                                <span>{{ $attachment->uploader?->name ?: 'مشرف' }}</span>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-black {{ $expired ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' }}">
                            {{ $expired ? 'انتهت الصلاحية' : 'متبقي '.$remainingDays.' يوم' }}
                        </span>
                    </div>

                    @if($attachment->note)
                        <p class="mt-3 rounded-xl bg-white px-3 py-2 text-xs font-bold leading-5 text-gray-600">{{ $attachment->note }}</p>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200/70 pt-3">
                        <p class="text-[11px] font-bold text-gray-400">يُحذف {{ $attachment->expires_at?->format('d/m/Y h:i A') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @unless($expired)
                                <a href="{{ route('admin.orders.attachments.show', $attachment) }}" target="_blank" rel="noopener" class="rounded-lg bg-white px-3 py-2 text-xs font-black text-sky-700 shadow-sm hover:bg-sky-50">فتح</a>
                                <a href="{{ route('admin.orders.attachments.download', $attachment) }}" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-black text-white hover:bg-sky-700">تحميل</a>
                            @endunless
                            @can('orders.update')
                                <form method="POST" action="{{ route('admin.orders.attachments.destroy', $attachment) }}" onsubmit="return confirm('سيتم حذف المرفق نهائيًا. هل تريد المتابعة؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded-lg bg-red-50 px-3 py-2 text-xs font-black text-red-700 hover:bg-red-100">حذف</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </article>
            @empty
                <div class="md:col-span-2 rounded-2xl border border-dashed border-gray-200 p-7 text-center text-sm font-bold text-gray-400">لا توجد مرفقات لهذا الطلب بعد.</div>
            @endforelse
        </div>
    </section>
@endif

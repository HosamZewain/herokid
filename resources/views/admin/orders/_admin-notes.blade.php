@php
    $notes = collect($orderAdminNotes ?? []);
    $collapsible ??= false;
    $openWhenPresent ??= false;
@endphp

@if($collapsible)
    <details class="group rounded-2xl border {{ $notes->isNotEmpty() ? 'border-amber-300 bg-amber-50/40' : 'border-amber-100 bg-white' }} shadow-sm" @if($errors->hasAny(['body', 'attachment']) || ($openWhenPresent && $notes->isNotEmpty())) open @endif data-order-admin-notes>
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-right [&::-webkit-details-marker]:hidden">
            <span class="rounded-full {{ $notes->isNotEmpty() ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-800' }} px-3 py-1.5 text-xs font-black">{{ $notes->count() }} ملاحظة</span>
            <span>
                <span class="block text-base font-black text-gray-950">ملاحظات فريق العمل</span>
                <span class="mt-1 block text-xs font-bold text-gray-500">ملاحظات ومرفقات فريق العمل مع تسجيل كل تعديل أو حذف.</span>
            </span>
        </summary>
@endif

<section class="{{ $collapsible ? 'border-t border-amber-100 p-5' : 'rounded-3xl border border-amber-100 bg-white p-5 shadow-sm' }}" aria-labelledby="order-admin-notes-title">
    @unless($collapsible)
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-right">
            <h3 id="order-admin-notes-title" class="text-lg font-black text-gray-950">ملاحظات فريق العمل</h3>
            <p class="mt-1 text-xs font-bold text-gray-500">ملاحظات ومرفقات فريق العمل. التعديل والحذف حسب الصلاحيات ويُسجلان في سجل النشاط.</p>
        </div>
        <span class="self-start rounded-full bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">{{ $notes->count() }} ملاحظة</span>
    </div>
    @endunless

    @can('orders.update')
        <form method="POST" action="{{ route('admin.orders.notes.store', $noteTargetOrder) }}" enctype="multipart/form-data" class="mt-5 rounded-2xl border border-amber-100 bg-amber-50 p-4">
            @csrf
            <label for="order-admin-note-{{ $noteTargetOrder->id }}" class="mb-2 block text-xs font-black text-amber-950">إضافة ملاحظة جديدة</label>
            <textarea id="order-admin-note-{{ $noteTargetOrder->id }}" name="body" rows="3" maxlength="5000" required class="w-full rounded-xl border-amber-200 bg-white text-right text-sm leading-7 focus:border-amber-500 focus:ring-amber-500" placeholder="اكتب المعلومة أو المتابعة التي يجب أن تبقى محفوظة مع الطلب...">{{ old('body') }}</textarea>
            @error('body')<p class="mt-2 text-xs font-black text-rose-600" role="alert">{{ $message }}</p>@enderror
            <label class="mt-3 block text-xs font-black text-amber-950">
                مرفق اختياري
                <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif" class="mt-2 block w-full rounded-xl border border-amber-200 bg-white text-sm file:ml-3 file:border-0 file:bg-amber-600 file:px-4 file:py-2.5 file:font-black file:text-white">
                <span class="mt-1 block text-[11px] font-bold text-amber-700">PDF أو صورة حتى 50MB — يُحذف تلقائيًا بعد 30 يومًا.</span>
            </label>
            @error('attachment')<p class="mt-2 text-xs font-black text-rose-600" role="alert">{{ $message }}</p>@enderror
            <div class="mt-3 flex justify-end">
                <button type="submit" class="min-h-11 rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-black text-white hover:bg-amber-700">حفظ الملاحظة</button>
            </div>
        </form>
    @endcan

    <div class="mt-5 space-y-3">
        @forelse($notes as $note)
            <article class="rounded-2xl border border-gray-100 bg-slate-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2 text-xs">
                    <div class="flex flex-wrap items-center gap-2 font-bold text-gray-500">
                        <time datetime="{{ $note->created_at->toIso8601String() }}" dir="ltr">{{ app_datetime($note->created_at) }}</time>
                        @if($note->last_edited_by_user_id)
                            <span class="rounded-full bg-indigo-50 px-2 py-1 text-[10px] font-black text-indigo-700">عُدلت بواسطة {{ $note->lastEditor?->name ?: 'مشرف' }} · {{ app_datetime($note->updated_at) }}</span>
                        @endif
                    </div>
                    <p class="font-black text-gray-900">{{ $note->author_name }}</p>
                </div>
                <p class="mt-3 whitespace-pre-wrap break-words text-sm font-bold leading-7 text-gray-800">{{ $note->body }}</p>

                @if($note->attachment)
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-100 bg-white p-3" data-order-attachment-id="{{ $note->attachment->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-black text-gray-900" title="{{ $note->attachment->original_name }}">{{ $note->attachment->icon }} {{ $note->attachment->original_name }}</p>
                            <p class="mt-1 text-[11px] font-bold text-gray-500">{{ $note->attachment->human_size }} · {{ $note->attachment->isExpired() ? 'انتهت الصلاحية' : 'صالح حتى '.app_datetime($note->attachment->expires_at) }}</p>
                        </div>
                        @unless($note->attachment->isExpired())
                            <div class="flex gap-2">
                                <a href="{{ route('admin.orders.attachments.show', $note->attachment) }}" target="_blank" rel="noopener" class="rounded-lg bg-sky-50 px-3 py-2 text-xs font-black text-sky-700">فتح</a>
                                <a href="{{ route('admin.orders.attachments.download', $note->attachment) }}" class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-black text-white">تحميل</a>
                            </div>
                        @endunless
                    </div>
                @endif

                @if(auth()->user()->hasAnyPermission(['orders.notes.edit', 'orders.notes.delete']))
                    <div class="mt-3 flex flex-wrap justify-end gap-2 border-t border-gray-200 pt-3">
                        @can('orders.notes.delete')
                            <form method="POST" action="{{ route('admin.orders.notes.destroy', [$noteTargetOrder, $note]) }}" onsubmit="return confirm('هل تريد حذف هذه الملاحظة؟ سيظل أثر الحذف محفوظًا في سجل النشاط.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 hover:bg-rose-100">حذف</button>
                            </form>
                        @endcan
                        @can('orders.notes.edit')
                            <details class="group/edit">
                                <summary class="cursor-pointer list-none rounded-lg bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 hover:bg-indigo-100 [&::-webkit-details-marker]:hidden">تعديل</summary>
                                <form method="POST" action="{{ route('admin.orders.notes.update', [$noteTargetOrder, $note]) }}" enctype="multipart/form-data" class="mt-3 min-w-[min(36rem,75vw)] rounded-2xl border border-indigo-100 bg-white p-4 text-right shadow-sm">
                                    @csrf
                                    @method('PUT')
                                    <label class="block text-xs font-black text-gray-700">نص الملاحظة
                                        <textarea name="body" rows="3" maxlength="5000" required class="mt-2 w-full rounded-xl border-gray-200 text-right text-sm leading-7">{{ $note->body }}</textarea>
                                    </label>
                                    <label class="mt-3 block text-xs font-black text-gray-700">{{ $note->attachment ? 'استبدال المرفق (اختياري)' : 'إضافة مرفق (اختياري)' }}
                                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif" class="mt-2 block w-full rounded-xl border border-gray-200 bg-white text-sm file:ml-3 file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:font-black file:text-white">
                                    </label>
                                    @if($note->attachment)
                                        <label class="mt-3 flex items-center justify-end gap-2 text-xs font-black text-rose-700">
                                            <span>حذف المرفق الحالي بدون استبداله</span>
                                            <input type="checkbox" name="remove_attachment" value="1" class="rounded border-gray-300 text-rose-600">
                                        </label>
                                    @endif
                                    <div class="mt-3 flex justify-end">
                                        <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-black text-white hover:bg-indigo-700">حفظ التعديل</button>
                                    </div>
                                </form>
                            </details>
                        @endcan
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-200 px-4 py-8 text-center text-sm font-bold text-gray-400">لا توجد ملاحظات داخلية محفوظة بعد.</div>
        @endforelse
    </div>
</section>

@if($collapsible)
    </details>
@endif

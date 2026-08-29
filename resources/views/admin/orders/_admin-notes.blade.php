@php
    $notes = collect($orderAdminNotes ?? []);
    $collapsible ??= false;
@endphp

@if($collapsible)
    <details class="group rounded-2xl border border-amber-100 bg-white shadow-sm" @if($errors->has('body')) open @endif>
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-right [&::-webkit-details-marker]:hidden">
            <span class="rounded-full bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">{{ $notes->count() }} ملاحظة</span>
            <span>
                <span class="block text-base font-black text-gray-950">ملاحظات فريق العمل</span>
                <span class="mt-1 block text-xs font-bold text-gray-500">سجل دائم لا يمكن تعديل ملاحظاته أو حذفها.</span>
            </span>
        </summary>
@endif

<section class="{{ $collapsible ? 'border-t border-amber-100 p-5' : 'rounded-3xl border border-amber-100 bg-white p-5 shadow-sm' }}" aria-labelledby="order-admin-notes-title">
    @unless($collapsible)
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-right">
            <h3 id="order-admin-notes-title" class="text-lg font-black text-gray-950">ملاحظات فريق العمل</h3>
            <p class="mt-1 text-xs font-bold text-gray-500">سجل دائم لعملية الشراء. الملاحظات المحفوظة لا يمكن تعديلها أو حذفها.</p>
        </div>
        <span class="self-start rounded-full bg-amber-50 px-3 py-1.5 text-xs font-black text-amber-800">{{ $notes->count() }} ملاحظة</span>
    </div>
    @endunless

    @can('orders.update')
        <form method="POST" action="{{ route('admin.orders.notes.store', $noteTargetOrder) }}" class="mt-5 rounded-2xl border border-amber-100 bg-amber-50 p-4">
            @csrf
            <label for="order-admin-note-{{ $noteTargetOrder->id }}" class="mb-2 block text-xs font-black text-amber-950">إضافة ملاحظة جديدة</label>
            <textarea id="order-admin-note-{{ $noteTargetOrder->id }}" name="body" rows="3" maxlength="5000" required class="w-full rounded-xl border-amber-200 bg-white text-right text-sm leading-7 focus:border-amber-500 focus:ring-amber-500" placeholder="اكتب المعلومة أو المتابعة التي يجب أن تبقى محفوظة مع الطلب...">{{ old('body') }}</textarea>
            @error('body')<p class="mt-2 text-xs font-black text-rose-600" role="alert">{{ $message }}</p>@enderror
            <div class="mt-3 flex justify-end">
                <button type="submit" class="min-h-11 rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-black text-white hover:bg-amber-700">حفظ الملاحظة</button>
            </div>
        </form>
    @endcan

    <div class="mt-5 space-y-3">
        @forelse($notes as $note)
            <article class="rounded-2xl border border-gray-100 bg-slate-50 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-2 text-xs">
                    <time datetime="{{ $note->created_at->toIso8601String() }}" class="font-bold text-gray-500" dir="ltr">{{ $note->created_at->format('d/m/Y h:i A') }}</time>
                    <p class="font-black text-gray-900">{{ $note->author_name }}</p>
                </div>
                <p class="mt-3 whitespace-pre-wrap break-words text-sm font-bold leading-7 text-gray-800">{{ $note->body }}</p>
            </article>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-200 px-4 py-8 text-center text-sm font-bold text-gray-400">لا توجد ملاحظات داخلية محفوظة بعد.</div>
        @endforelse
    </div>
</section>

@if($collapsible)
    </details>
@endif

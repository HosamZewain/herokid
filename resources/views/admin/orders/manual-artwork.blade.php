<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">استخدام المعاينات المرفوعة واستكمال الطلب</h2></x-slot>
    <div class="mx-auto max-w-4xl space-y-4 p-4" dir="rtl">
        <a href="{{ route('admin.orders.groups.show', $order) }}" class="font-bold text-indigo-700">العودة للطلب</a>
        <p>اختر الصورة الصحيحة لكل منتج متعثر. سيتم الاحتفاظ بالمحاولات السابقة واستخدام الصور المختارة دون توليد مدفوع جديد. هذا الربط لا يسجّل موافقة العميل أو الدفع؛ سيستكمل مسار المعاينة المعتاد.</p>
        @foreach($errors->all() as $message)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $message }}</p>@endforeach
        @if($error)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $error }}</p>@endif
        @if($offers)
        <form method="POST" action="{{ route('admin.orders.workspace.manual-artwork-adopt', $order) }}" class="space-y-4">
            @csrf
            @foreach($offers as $offer)
                <fieldset class="rounded-xl border bg-white p-4">
                    <legend class="font-bold">{{ $offer['item']->title }} — {{ $offer['item']->order->child_name }}</legend>
                    <div class="flex flex-wrap gap-4">
                    @forelse($offer['choices'] as $choice)
                        <label class="rounded-xl border p-3">
                            <a href="{{ route('admin.orders.product-previews.thumbnail', $choice['preview']) }}" target="_blank" rel="noopener"><img src="{{ route('admin.orders.product-previews.thumbnail', $choice['preview']) }}" alt="معاينة مرفوعة رقم {{ $choice['preview']->id }}" loading="lazy" class="max-h-52 rounded-lg"></a>
                            <span class="mt-2 block"><input type="radio" name="choices[{{ $offer['item']->id }}]" value="{{ $choice['token'] }}" required> استخدام صورة #{{ $choice['preview']->id }} لهذا المنتج</span>
                        </label>
                    @empty
                        <p>لا توجد صورة متاحة. ارفع الصورة من تبويب المعاينات في الطلب ثم أعد فتح الصفحة.</p>
                    @endforelse
                    </div>
                </fieldset>
            @endforeach
            <button name="confirm" value="1" class="rounded-xl bg-indigo-700 px-5 py-3 font-bold text-white">استخدام الصور المحددة واستئناف المعاينة</button>
        </form>
        @endif
    </div>
</x-admin-layout>

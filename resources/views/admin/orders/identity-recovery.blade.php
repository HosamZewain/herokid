<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">استكمال بالهوية المعتمدة الجديدة</h2></x-slot>
    <div class="mx-auto max-w-4xl space-y-4 p-4" dir="rtl">
        <a href="{{ route('admin.orders.groups.show', $order) }}" class="font-bold text-indigo-700">العودة للطلب</a>
        <p>راجع صور هذه النسخة التي وافق عليها العميل. سيتم ربط الهوية البديلة بالمعاينة الحالية مع حفظ النسخة السابقة، ثم استكمال المتبقي المؤهل حسب الدفعة المسجلة.</p>
        @foreach($errors->all() as $message)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $message }}</p>@endforeach
        @if($error)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $error }}</p>@endif
        @if($plan)
        <form method="POST" action="{{ route('admin.orders.workspace.identity-recover', $order) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="preview_token" value="{{ app(\App\Services\Orders\ApprovedIdentityRecovery::class)->token($plan) }}">
            @foreach($plan['images'] as $image)
            <section class="rounded-xl border bg-white p-4">
                <h3 class="font-bold">{{ $image['label'] }}</h3>
                @php($imageUrl = ($image['kind'] ?? '') === 'identity'
                    ? route('admin.orders.identity-attempts.image', [$image['order_id'], $image['attempt_id']])
                    : route('admin.orders.product-previews.thumbnail', $image['preview_id']))
                <a href="{{ $imageUrl }}" target="_blank" rel="noopener"><img src="{{ $imageUrl }}" alt="صورة المعاينة الحالية" class="my-3 max-h-52 rounded-lg"></a>
                @if(isset($plan['replaced'][$image['order_id']]) && ($image['kind'] ?? '') === 'identity')
                    <p class="font-bold text-emerald-800">الهوية البديلة المعتمدة — ستُستخدم لاستكمال هذه القصة.</p>
                @else
                    <p>الصورة الحالية محفوظة كما هي.</p>
                @endif
            </section>
            @endforeach
            <p>لن تُرسل رسالة معاينة أخرى أو تُولد هوية جديدة بهذا الإجراء. الصور السابقة وموافقتها تبقى في السجل.</p>
            <label class="block font-bold"><input type="checkbox" name="confirm" value="1" required> أسجّل أن العميل وافق على الهوية البديلة وجميع الصور المعروضة في هذه النسخة.</label>
            <button class="rounded-xl bg-indigo-700 px-5 py-3 font-bold text-white">تسجيل الموافقة على الهوية الحالية واستكمال الطلب</button>
        </form>
        @endif
    </div>
</x-admin-layout>

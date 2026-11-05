<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">{{ $replacement ? 'اعتماد الكفر البديل واستكمال الكتاب' : 'تجهيز كتاب قديم بالمعاينة الموجودة' }}</h2></x-slot>
    <div class="mx-auto max-w-4xl space-y-4 p-4" dir="rtl">
        <a href="{{ route('admin.orders.groups.show', $order) }}" class="font-bold text-indigo-700">العودة للطلب</a>
        <h3 class="text-lg font-bold">{{ $item->title }} — {{ $item->order->child_name }}</h3>
        <p>اختر الكفر الصحيح لهذا الطفل والكتاب بعد مراجعة موافقة العميل عليه. يُربط الكفر المحدد ببيانات الطفل وإعدادات الصفحات الحالية، مع الاحتفاظ بالصور والمرفقات وسجل التوليد السابق.</p>
        <p>{{ $replacement ? 'يسجل الزر موافقة العميل على الصورة المحددة ويبدأ الصفحات إن كانت الشروط والدفعة مكتملة؛ وإلا يوضح المطلوب. لا يعيد توليد الكفر ولا يرسل رسالة.' : 'تسجيل الموافقة لا يعيد توليد الكفر ولا يرسل رسالة. بدء الصفحات يحتاج دفعة مسجلة ويظهر كإجراء مستقل في صفحة الطلب.' }}</p>
        @foreach($errors->all() as $message)<p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ $message }}</p>@endforeach
        @if($error)<p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{{ $error }}</p>@endif
        @if(!$error && !$offers)
            <p>لا توجد معاينة كفر مرفوعة في عملية الشراء هذه. ارفع الكفر من «معاينة المنتجات للعميل» ثم ارجع لاختياره لهذا الكتاب.</p>
            <a href="{{ route('admin.orders.groups.show', $order) }}#product-customer-preview" class="font-bold text-indigo-700">فتح مكان رفع الكفر في الطلب</a>
        @endif
        <form method="POST" action="{{ route('admin.orders.workspace.legacy-book-approve', [$order, $item]) }}" class="space-y-4">
            @csrf
            @if($replacement)<input type="hidden" name="replacement" value="1">@endif
            @foreach($offers as $offer)
            @php($preview = $offer['preview'])
            <label class="block rounded-xl border bg-white p-4">
                @if(isset($offer['token']))
                    <input type="radio" name="preview_token" value="{{ $offer['token'] }}" required>
                    <strong>{{ $offer['pages'] }} صفحة داخلية — {{ $item->order->child_name }}</strong>
                @endif
                <p class="my-2">معاينة #{{ $preview->id }} — {{ $preview->original_name }}</p>
                @if(!$preview->production_unit_key)
                    <p class="my-2 font-bold text-amber-800">صورة مرفوعة على عملية الشراء ولم تُربط بمنتج. تأكد بصريًا أن الكفر يخص {{ $item->order->child_name }} وكتاب {{ $item->title }} قبل تسجيل الموافقة.</p>
                @endif
                @if($token = $preview->productGallery?->plainPublicToken())
                    <a href="{{ route('order-product-previews.show', $token) }}" target="_blank" rel="noopener" class="font-bold text-indigo-700">فتح المعاينة للمراجعة</a>
                    <img src="{{ route('order-product-previews.image', [$token, $preview]) }}" class="my-3 max-h-96 max-w-full rounded-lg" alt="كفر {{ $item->order->child_name }} — معاينة {{ $preview->id }}">
                @endif
                @if(isset($offer['error']))<p class="text-red-800">{{ $offer['error'] }}</p>@endif
            </label>
            @endforeach
            @if(collect($offers)->contains(fn ($offer) => isset($offer['token'])))
            <p>بالضغط على الزر أسجّل موافقة العميل على الكفر المحدد لهذا الطفل وعدد الصفحات المعروض.</p>
            <button name="confirm" value="1" class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">{{ $replacement ? 'تسجيل الموافقة وبدء الصفحات بالكفر المحدد' : 'تسجيل الموافقة وتجهيز الكتاب بالكفر المحدد' }}</button>
            @endif
        </form>
    </div>
</x-admin-layout>

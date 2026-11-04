<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-bold">استكمال بعد تغيير القصة</h2></x-slot>
    <div class="mx-auto max-w-4xl space-y-4 p-4" dir="rtl">
        <a href="{{ route('admin.orders.groups.show',$order) }}" class="font-bold text-indigo-700">العودة للطلب</a>
        <p>راجع القصة الحالية والمنتجات. هذا الإجراء يؤكد بيانات الطلب المعدّل ويجهّز معاينة جديدة تحتاج موافقة العميل؛ لا يعتمد الصور أو الدفع ولا يعيد رسالة تأكيد الطلب.</p>
        @foreach($errors->all() as $message)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $message }}</p>@endforeach
        @if($error)<p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $error }}</p>@endif
        @if($plan)
        <form method="POST" action="{{ route('admin.orders.workspace.story-recover',$order) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="preview_token" value="{{ app(\App\Services\Orders\StoryPreviewRecovery::class)->token($plan) }}">
            @foreach($plan['storyRows'] as $story)
            <section class="rounded-xl border bg-white p-4">
                <h3 class="font-bold">{{ $story['order']->story->title }} — {{ $story['order']->child_name }}</h3>
                <p>{{ $story['mode']==='reuse' ? 'هوية هذه القصة مطابقة ومحفوظة؛ ستُستخدم في المعاينة الجديدة.' : 'سيتم تجهيز هوية للقصة الحالية؛ الهوية والمحاولات السابقة تبقى في السجل.' }}</p>
            </section>
            @endforeach
            @foreach($plan['products'] as $id=>$product)
            <section class="rounded-xl border bg-white p-4">
                <h3 class="font-bold">{{ $product['item']->title }} — {{ $product['item']->order->child_name }}</h3>
                <a href="{{ route('admin.orders.product-previews.thumbnail',$product['preview']) }}" target="_blank" rel="noopener"><img src="{{ route('admin.orders.product-previews.thumbnail',$product['preview']) }}" alt="صورة المنتج الحالية" loading="lazy" class="my-3 max-h-52 rounded-lg"></a>
                @if($product['changed'])
                    <p class="mb-2 font-bold text-amber-800">تغيّرت بيانات أو صور مرجع هذا المنتج. راجع الصورة الحالية وحدد المطلوب.</p>
                    <fieldset><legend class="font-bold">التصرف في صورة المنتج #{{ $id }}</legend>
                        <label class="my-2 block"><input type="radio" name="products[{{ $id }}]" value="retain" required> الاحتفاظ بالصورة المعروضة وإرسالها ضمن المعاينة الجديدة بالبيانات الحالية.</label>
                        <label class="my-2 block"><input type="radio" name="products[{{ $id }}]" value="regenerate" required> إعادة توليد هذا المنتج بالمراجع الحالية؛ محاولة مدفوعة جديدة مع حفظ الصورة السابقة.</label>
                    </fieldset>
                @else
                    <p class="font-bold text-emerald-800">الصورة والمراجع مطابقة — سيتم الاحتفاظ بها دون توليد جديد.</p>
                @endif
            </section>
            @endforeach
            <p>بالضغط على الزر أسجّل أن العميل طلب القصة والبيانات الحالية، وأطلب تجهيز المعاينة بالاختيارات أعلاه. اعتماد المعاينة يأتي بعد مراجعتها.</p>
            <button name="confirm" value="1" class="rounded-xl bg-indigo-700 px-5 py-3 font-bold text-white">تأكيد التعديل وتجهيز المعاينة الجديدة</button>
        </form>
        @endif
    </div>
</x-admin-layout>

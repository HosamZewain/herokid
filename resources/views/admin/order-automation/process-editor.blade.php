<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">بناء المسار — {{ $workflow->name }}</h1></x-slot>
    @php
        $editing = json_decode(old('definition_json', json_encode($definition)), true);
        if (!is_array($editing) || ($editing['schema'] ?? null) !== 2 || !is_array($editing['nodes'] ?? null) || !is_array(data_get($editing,'conditions.story_ids')) || !is_array(data_get($editing,'conditions.product_ids'))) $editing = $definition;
    @endphp
    <div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        <a class="text-indigo-700 underline" href="{{ route('admin.order-automation.index') }}">← كل مسارات الأتمتة</a>
        @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900"><strong>بيئة بناء ومحاكاة</strong> — تقدر تحفظ المسار وتختبر فروعه ببيانات وهمية. المحاكاة لا ترسل رسائل ولا تغيّر طلبات حقيقية. التنفيذ على العملاء له تفعيل مستقل وشروط جاهزية.</div>
        @if(auth()->user()->hasPermission('orders.view'))<a class="inline-block rounded-lg border bg-white px-4 py-2 text-indigo-700" href="{{ route('admin.order-automation.process.live', $workflow) }}">التنفيذ الفعلي والإحصائيات</a>@endif
        <form method="POST" action="{{ route('admin.order-automation.process.save', $workflow) }}" class="space-y-6">@csrf @method('PUT')
            <input type="hidden" name="draft_version" value="{{ $workflow->draft_version }}">
            <input type="hidden" name="definition_json" value="{{ json_encode($editing, JSON_UNESCAPED_UNICODE) }}">
            <div class="space-y-5 rounded-2xl border bg-white p-6">
                <label class="block font-bold">اسم المسار<input name="name" required maxlength="150" value="{{ old('name', $workflow->name) }}" class="mt-2 w-full rounded-xl border-gray-300"></label>
                <h2 class="text-xl font-bold">١. متى يبدأ المسار؟</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <label>الحدث<select data-trigger class="mt-2 w-full rounded-lg border-gray-300">@foreach(['checkout_created'=>'وصول عملية شراء جديدة مكتملة','status_entered'=>'انتقال الطلب إلى حالة'] as $key=>$label)<option value="{{ $key }}" @selected(data_get($editing,'trigger')===$key)>{{ $label }}</option>@endforeach</select></label>
                    <label>الحالة المطلوبة<select data-status class="mt-2 w-full rounded-lg border-gray-300">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(data_get($editing,'conditions.status')===$key)>{{ $label }}</option>@endforeach</select></label>
                    <label>نطاق التنفيذ<select data-scope class="mt-2 w-full rounded-lg border-gray-300">@foreach(['checkout'=>'مرة لكل عملية شراء','story'=>'على عناصر القصص المطابقة','product'=>'على عناصر المنتجات المطابقة'] as $key=>$label)<option value="{{ $key }}" @selected(data_get($editing,'scope')===$key)>{{ $label }}</option>@endforeach</select></label>
                    <label>يحتوي على<select data-target class="mt-2 w-full rounded-lg border-gray-300">@foreach(['any'=>'أي طلب — قصص أو منتجات','story'=>'قصص','product'=>'منتجات'] as $key=>$label)<option value="{{ $key }}" @selected(data_get($editing,'conditions.type')===$key)>{{ $label }}</option>@endforeach</select></label>
                    <label>قصص محددة<select data-stories multiple class="mt-2 w-full rounded-lg border-gray-300">@foreach($stories as $story)<option value="{{ $story->id }}" @selected(in_array($story->id, data_get($editing,'conditions.story_ids',[])))>{{ $story->title }}</option>@endforeach</select><span class="text-sm text-slate-500">بدون تحديد = كل القصص.</span></label>
                    <label>منتجات محددة<select data-products multiple class="mt-2 w-full rounded-lg border-gray-300">@foreach($products as $product)<option value="{{ $product->id }}" @selected(in_array($product->id, data_get($editing,'conditions.product_ids',[])))>{{ $product->name_ar ?: $product->name_en }}</option>@endforeach</select><span class="text-sm text-slate-500">بدون تحديد = كل المنتجات. الاختيار هنا على مستوى المنتج.</span></label>
                </div>
                <p class="text-sm text-slate-500">يكفي وجود عنصر مطابق لشروط النوع والأصناف. نطاق عملية الشراء ينفذ مرة واحدة؛ نطاق العناصر يستهدف العناصر المطابقة فقط. انتقال الحالة حدث مستقل، وليس تكرارًا للتشغيل طوال وجود الطلب في الحالة.</p>
            </div>
            <div data-process-builder data-initial="{{ json_encode($definition, JSON_UNESCAPED_UNICODE) }}" data-statuses="{{ json_encode($statuses, JSON_UNESCAPED_UNICODE) }}" data-variables="{{ json_encode(\App\Services\OrderAutomation\Process\ProcessDefinition::VARIABLES + \App\Services\OrderAutomation\Process\ProcessTemplates::EXTRA_VARIABLES, JSON_UNESCAPED_UNICODE) }}" class="space-y-4">
                <h2 class="text-xl font-bold">٢. خطوات المسار وفروعه</h2>
                <p class="text-sm text-slate-500">كل خطوة تحدد الخطوة التالية. الفروع تتجه لخطوات لاحقة؛ راجع الروابط بعد الحذف أو إعادة الترتيب.</p>
                <datalist id="process-templates"><option value="herokid_preview_review_payment">المعاينة المجمعة والدفع</option><option value="herokid_order_confirmation">قالب تأكيد الطلب</option><option value="herokid_identity_review_payment">معاينة الهوية والدفع</option></datalist>
                <div data-nodes class="space-y-3"></div>
                <div class="flex flex-wrap gap-3 rounded-xl border border-dashed border-indigo-300 bg-indigo-50 p-4">
                    <select data-new-type aria-label="نوع الخطوة الجديدة" class="rounded-lg border-gray-300">@foreach(\App\Services\OrderAutomation\Process\ProcessDefinition::TYPES as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                    <button type="button" data-add class="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white">+ إضافة خطوة</button>
                </div>
                <p class="text-sm text-slate-500">توليد الهوية وحفظها واعتمادها متاح للتصميم والمحاكاة. التشغيل الفعلي له تفعيل وميزانية منفصلان. مراجعة الدفع تظل خطوة موظف، ولا يغيّر رد واتساب رصيد الطلب.</p>
            </div>
            <noscript><p>فعّل JavaScript لاستخدام محرر الخطوات.</p></noscript>
            <button data-save disabled class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white disabled:opacity-40">حفظ المسودة</button>
        </form>
        <form method="POST" action="{{ route('admin.order-automation.process.publish', $workflow) }}" class="space-y-3 rounded-2xl border bg-white p-5">@csrf
            <h2 class="text-xl font-bold">٣. حفظ نسخة ثابتة للاختبار</h2>
            <p class="text-sm text-slate-500">احفظ المسودة أولًا. إنشاء النسخة لا يفعّل التشغيل على العملاء.</p>
            <input type="hidden" name="draft_version" value="{{ $workflow->draft_version }}"><label class="block"><input type="checkbox" name="confirmed" value="1" required> راجعت المسودة المحفوظة</label><button class="rounded-lg border px-4 py-2">إنشاء نسخة للمحاكاة</button>
        </form>
        @if($workflow->revision_id)
        <form method="POST" action="{{ route('admin.order-automation.process.simulate', $workflow) }}" class="space-y-4 rounded-2xl border bg-white p-5">@csrf
            <h2 class="text-xl font-bold">٤. محاكاة النسخة #{{ $workflow->revision_id }} ببيانات وهمية</h2><p class="text-sm text-slate-500">لا تُدخل رقم طلب أو بيانات عميل. القيمة الافتراضية للقالب في المحاكاة 450 جنيه.</p>
            <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="grid gap-4 md:grid-cols-2">
                <label>الحدث<select name="fixture[event]" class="mt-2 w-full rounded-lg border-gray-300"><option value="checkout_created" @selected(data_get($workflow->revision->definition,'trigger')==='checkout_created')>وصول طلب جديد</option><option value="status_entered" @selected(data_get($workflow->revision->definition,'trigger')==='status_entered')>انتقال حالة</option></select></label>
                <label>الحالة<select name="fixture[status]" class="mt-2 w-full rounded-lg border-gray-300">@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected($key===data_get($workflow->revision->definition,'conditions.status'))>{{ $label }}</option>@endforeach</select></label>
                <label>الحالة السابقة — لحدث انتقال الحالة<select name="fixture[previous_status]" class="mt-2 w-full rounded-lg border-gray-300"><option value="">لا توجد</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label>نوع العنصر التجريبي<select name="fixture[items][0][type]" class="mt-2 w-full rounded-lg border-gray-300"><option value="story">قصة</option><option value="product">منتج</option></select></label>
                <label>معرّف القصة أو المنتج في الكتالوج<input type="number" min="1" name="fixture[items][0][catalog_id]" value="{{ data_get($workflow->revision->definition, 'conditions.story_ids.0', data_get($workflow->revision->definition, 'conditions.product_ids.0', 1)) }}" required class="mt-2 w-full rounded-lg border-gray-300"><span class="text-sm text-slate-500">رقم صنف لا رقم أوردر؛ يمكن استخدام 1 لاختبار شرط «الكل».</span></label>
            </div><button class="rounded-xl bg-slate-900 px-5 py-3 font-bold text-white">ابدأ المحاكاة</button>
        </form>
        @endif
        <section class="space-y-3 rounded-2xl border bg-white p-5"><h2 class="text-xl font-bold">إحصائيات المحاكاة فقط</h2><div class="flex flex-wrap gap-2">@foreach($counts as $status=>$count)<span class="rounded-full bg-indigo-50 px-3 py-2 text-indigo-800">{{ \App\Services\OrderAutomation\Process\ProcessSimulator::STATES[$status] ?? $status }}: {{ $count }}</span>@endforeach</div>
            <div class="overflow-x-auto"><table class="w-full text-right"><thead><tr><th class="p-3">المحاكاة</th><th>نسخة المسار</th><th>الحالة</th><th>وقت الإنشاء</th></tr></thead><tbody>@forelse($simulations as $run)<tr class="border-t"><td class="p-3"><a class="text-indigo-700 underline" href="{{ route('admin.order-automation.process.simulation', $run) }}">#{{ $run->id }}</a></td><td>#{{ $run->revision_id }}</td><td>{{ \App\Services\OrderAutomation\Process\ProcessSimulator::STATES[$run->status] ?? $run->status }}</td><td>{{ $run->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</td></tr>@empty<tr><td class="p-4" colspan="4">لا توجد محاكاة بعد.</td></tr>@endforelse</tbody></table></div>{{ $simulations->links() }}
        </section>
        <details class="rounded-xl border bg-white p-5"><summary class="cursor-pointer font-bold">النسخ المحفوظة</summary>@foreach($history as $version)<p class="mt-3">#{{ $version->id }} · {{ $version->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }} · {{ count($version->definition['nodes'] ?? []) }} خطوة</p>@endforeach</details>
    </div>
</x-admin-layout>

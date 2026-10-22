<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">إعداد مسار الأتمتة — {{ $workflow->name }}</h1></x-slot>
    <div class="mx-auto max-w-5xl space-y-6 p-6" dir="rtl">
        <a href="{{ route('admin.order-automation.index') }}" class="text-indigo-700 underline">كل المسارات وسجل التشغيل</a>
        @include('admin.order-automation.notice')
        <form method="POST" action="{{ route('admin.order-automation.save', $workflow) }}" class="space-y-5 rounded-2xl border bg-white p-6">@csrf @method('PUT')
            <input type="hidden" name="draft_version" value="{{ $workflow->draft_version }}">
            <label class="block">اسم المسار<input name="name" value="{{ old('name', $workflow->name) }}" required maxlength="150" class="mt-2 w-full rounded-lg border-gray-300"></label>
            <p>عند وصول طلب جديد مكتمل البيانات • نوع العنصر: قصة. مشغّل المنتجات سيُضاف كنوع خطوة مستقل لاحقًا.</p>
            <label class="block">قصص محددة (اتركها فارغة لكل القصص)<select multiple name="definition[story_ids][]" class="mt-2 w-full rounded-lg border-gray-300">@foreach($stories as $story)<option value="{{ $story->id }}" @selected(in_array($story->id, old('definition.story_ids', $definition['story_ids'])))>{{ $story->title }}</option>@endforeach</select></label>
            <div class="grid gap-4 md:grid-cols-2">
                <label>المزوّد والموديل<select required name="definition[model_id]" class="mt-2 w-full rounded-lg border-gray-300"><option value="">اختر الموديل</option>@foreach($models as $model)<option value="{{ $model->id }}" @selected(old('definition.model_id', $definition['model_id']) == $model->id)>{{ $model->provider?->display_name }} — {{ $model->display_name }} ({{ $model->supportsMultipleReferences() ? 'عدة صور' : 'صورة واحدة' }})</option>@endforeach</select></label>
                <label>عدد الصور المرجعية (حسب ترتيب صور الطلب)<input type="number" name="definition[photo_limit]" min="1" max="4" required value="{{ old('definition.photo_limit', $definition['photo_limit']) }}" class="mt-2 w-full rounded-lg border-gray-300"></label>
                <label>الجودة<select name="definition[quality]" class="mt-2 w-full rounded-lg border-gray-300">@foreach(['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية'] as $key=>$label)<option value="{{ $key }}" @selected(old('definition.quality', $definition['quality']) == $key)>{{ $label }}</option>@endforeach</select></label>
                <label>مقاس الصورة<select name="definition[size]" class="mt-2 w-full rounded-lg border-gray-300">@foreach(['1536x1024','1024x1536','1024x1024'] as $size)<option @selected(old('definition.size', $definition['size']) == $size)>{{ $size }}</option>@endforeach</select></label>
            </div>
            <label class="block">قالب البرومبت<textarea id="workflow-prompt" name="definition[prompt]" rows="7" required maxlength="30000" class="mt-2 w-full rounded-lg border-gray-300" dir="auto">{{ old('definition.prompt', $definition['prompt']) }}</textarea></label>
            <p class="text-sm text-gray-600">المتغيرات المتاحة: @verbatim {{identity_prompt}}، {{child_name}}، {{child_age}}، {{child_gender}}، {{story_title}}، {{order_reference}} @endverbatim. متغير برومبت الهوية يستخدم إعدادات الهوية الحالية وسياق الطفل والقصة.</p>
            <fieldset class="space-y-3 rounded-xl bg-slate-50 p-4"><legend class="font-bold">خطوات المسار</legend>
                @foreach(\App\Services\OrderAutomation\WorkflowDefinition::LABELS as $step=>$label)
                <label class="flex items-center gap-3 rounded-lg border bg-white p-3">
                    @if(in_array($step, ['prepare','generate','save','wait']))<input type="hidden" name="definition[steps][]" value="{{ $step }}"><span>●</span>
                    @else<input type="checkbox" name="definition[steps][]" value="{{ $step }}" @checked(in_array($step, old('definition.steps', $definition['steps'])))>@endif
                    {{ $loop->iteration }}. {{ $label }} @if(in_array($step,['review','whatsapp']))<span class="text-sm text-gray-500">اختياري</span>@endif
                </label>@endforeach
                <p class="text-sm">اترك الإرسال غير محدد ليكتمل المسار بحفظ الهوية فقط. تستطيع إضافة أو إزالة المراجعة والإرسال في أي نسخة لاحقة. مراجعة الموظف لا تعني موافقة ولي الأمر.</p>
            </fieldset>
            <label class="block">اسم قالب واتساب<input name="definition[template]" value="{{ old('definition.template', $definition['template']) }}" maxlength="120" class="mt-2 w-full rounded-lg border-gray-300" dir="ltr"></label>
            <div class="grid gap-4 md:grid-cols-3">@foreach(['max_cost_usd'=>'حد التعرض المالي للمحاولة بالدولار','daily_budget_usd'=>'ميزانية يومية بالدولار','max_concurrent'=>'التوليدات المتزامنة'] as $key=>$label)<label>{{ $label }}<input type="number" name="definition[{{ $key }}]" value="{{ old('definition.'.$key, $definition[$key]) }}" required min="{{ $key === 'max_concurrent' ? 1 : '0.01' }}" step="{{ $key === 'max_concurrent' ? 1 : '0.01' }}" class="mt-2 w-full rounded-lg border-gray-300"></label>@endforeach</div>
            <p class="text-sm text-gray-600">يُحجز حد المحاولة من الميزانية حتى مع نتيجة غير مؤكدة. الحجز ليس فاتورة فعلية؛ الفاتورة يحددها المزوّد.</p>
            <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ المسودة</button>
        </form>
        <form method="POST" action="{{ route('admin.order-automation.publish', $workflow) }}" class="space-y-3 rounded-xl border bg-white p-5">@csrf
            <input type="hidden" name="draft_version" value="{{ $workflow->draft_version }}"><label class="block"><input type="checkbox" name="confirmed" value="1" required> أنشر المسودة المحفوظة كنسخة جديدة؛ يتوقف المسار ويحتاج إعادة تفعيل منفصلة</label><button class="rounded border px-4 py-2">نشر النسخة</button>
        </form>
        @can('order_automation.execute')
        <form method="POST" action="{{ route('admin.order-automation.trial', $workflow) }}" class="space-y-3 rounded-xl border bg-white p-5">@csrf
            <h2 class="text-xl font-bold">اختبار النسخة المنشورة على طلب واحد</h2><input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label class="block">معرّف الطلب من رابط صفحة الطلب<input type="number" name="order_id" required min="1" class="mx-2 rounded-lg border-gray-300"></label>
            <label class="block">نوع الاختبار<select name="mode" class="mx-2 rounded-lg border-gray-300"><option value="simulate">محاكاة بدون توليد أو إرسال أو تغيير الطلب</option><option value="trial">توليد فعلي لطلب واحد وحفظ المعاينة — بدون واتساب</option></select></label>
            <label class="block"><input type="checkbox" name="confirmed" value="1" required> أؤكد الطلب المختار</label>
            <label class="block"><input type="checkbox" name="confirmed_billable" value="1"> أوافق على تكلفة التجربة الفعلية</label>
            <label class="block"><input type="checkbox" name="confirmed_processing" value="1"> راجعت إذن معالجة صور الطفل لدى مزوّد التوليد المختار</label>
            <button class="rounded-xl bg-indigo-600 px-4 py-2 text-white">بدء الاختبار</button>
        </form>@endcan
        <details class="rounded-xl border bg-white p-5"><summary>النسخ المنشورة السابقة</summary>@foreach($history as $entry)<p class="py-2">نسخة #{{ $entry->id }} · {{ $entry->created_at }} · {{ $entry->checksum }}</p>@endforeach</details>
    </div>
</x-admin-layout>

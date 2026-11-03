<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">{{ $memory ? 'مراجعة وتعديل الذاكرة' : 'إضافة تصحيح إلى الذاكرة' }} — HeroKid Auto Pilot</h1></x-slot>
    <main class="mx-auto max-w-4xl space-y-6 p-6" dir="rtl">
        <a href="{{ route('admin.agentic.memory.index') }}" class="text-indigo-700 underline">كل قواعد الذاكرة</a>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-3">{{ session('status') }}</p>@endif
        @if($errors->any())<ul role="alert" class="rounded-lg bg-red-50 p-3">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        @if($run)
            <section class="rounded-xl border bg-white p-4"><h2 class="font-bold">الاقتراح الذي تصحّحه</h2>
                <p>{{ $run->decision?->decision['reason'] ?? $run->error_code ?? $run->status }}</p>
                <p class="text-sm">التصحيح مرتبط بهذه المحاولة، ويبدأ كمسودة حتى تختار تنشيطه.</p>
            </section>
        @endif
        @if(auth()->user()->hasPermission('order_automation.manage'))
        <form method="POST" action="{{ $memory ? route('admin.agentic.memory.update',$memory) : route('admin.agentic.memory.store') }}" class="space-y-4 rounded-xl border bg-white p-5">
            @csrf @if($memory) @method('PUT') <input type="hidden" name="revision" value="{{ $memory->revision }}"> @endif
            @if($run)<input type="hidden" name="run_id" value="{{ $run->id }}">@endif
            <label class="block">عنوان القاعدة<input name="title" value="{{ old('title',$memory?->title) }}" required maxlength="160" class="mt-1 block w-full rounded-lg border-gray-300"></label>
            <label class="block">القاعدة أو التصحيح<textarea name="guidance" required maxlength="1200" rows="5" class="mt-1 block w-full rounded-lg border-gray-300">{{ old('guidance',$memory?->guidance) }}</textarea></label>
            <p class="text-sm text-gray-600">اكتب قاعدة عمل واضحة، بدون بيانات العملاء أو روابط الملفات أو مفاتيح الوصول. عند اختيار «نشطة» تدخل القاعدة في المراجعات القادمة ضمن حدود النظام.</p>
            @php($scope = old('scope',$memory ? ($memory->scope_type === 'capability' ? 'capability:'.$memory->scope_key : $memory->scope_type) : ($run ? 'checkout' : 'global')))
            <label class="block">تُطبَّق على<select name="scope" class="mx-2 rounded-lg border-gray-300">
                <option value="global" @selected($scope === 'global')>كل الطلبات</option>
                @foreach(['story_scenes'=>'القصص','single_image'=>'منتجات الصورة الواحدة','book_cover'=>'أغلفة الكتب','book_interior'=>'صفحات الكتب'] as $key=>$label)<option value="capability:{{ $key }}" @selected($scope === 'capability:'.$key)>{{ $label }}</option>@endforeach
                @if($run || $memory?->scope_type === 'checkout')<option value="checkout" @selected($scope === 'checkout')>الطلب المرتبط بالتصحيح فقط</option>@endif
            </select></label>
            <label class="block">الحالة<select name="status" class="mx-2 rounded-lg border-gray-300">@foreach(['draft','active','archived'] as $status)<option value="{{ $status }}" @selected(old('status',$memory?->status ?? 'draft') === $status)>{{ __('agentic.memory_status.'.$status) }}</option>@endforeach</select></label>
            <label class="block">سبب التعديل<input name="change_reason" required maxlength="500" value="{{ old('change_reason') }}" class="mt-1 block w-full rounded-lg border-gray-300"></label>
            <button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">حفظ نسخة الذاكرة</button>
        </form>
        @else
            <section class="rounded-xl border bg-white p-5"><h2 class="font-bold">{{ $memory?->title }}</h2><p class="whitespace-pre-line">{{ $memory?->guidance }}</p><p>صلاحيتك تسمح بالاطلاع على الذاكرة.</p></section>
        @endif
        @if($memory)
        <section class="space-y-3"><h2 class="text-xl font-bold">سجل النسخ</h2>
            @foreach($versions as $version)
                <details id="revision-{{ $version->revision }}" class="rounded-xl border bg-white p-4">
                    <summary>نسخة {{ $version->revision }} · {{ $version->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }} · {{ __('agentic.memory_status.'.$version->snapshot['status']) }}</summary>
                    <h3 class="font-bold">{{ $version->snapshot['title'] }}</h3><p class="whitespace-pre-line">{{ $version->snapshot['guidance'] }}</p>
                    <p>سبب التعديل: {{ $version->change_reason }}</p><p>المحرر: {{ $version->actor_id ? 'مستخدم #'.$version->actor_id : 'مراجعة المالك المستوردة' }}</p>
                    @if($version->revision !== $memory->revision && auth()->user()->hasPermission('order_automation.manage'))
                        <form method="POST" action="{{ route('admin.agentic.memory.restore',$memory) }}" class="mt-3">@csrf<input type="hidden" name="revision" value="{{ $memory->revision }}"><input type="hidden" name="restore_revision" value="{{ $version->revision }}"><button class="rounded-lg border px-3 py-2">استرجاع هذه النسخة كنسخة جديدة</button></form>
                    @endif
                </details>
            @endforeach
        </section>
        @endif
    </main>
</x-admin-layout>

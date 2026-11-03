<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">ذاكرة HeroKid Auto Pilot</h1></x-slot>
    <main class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
        <a href="{{ route('admin.agentic.index') }}" class="text-indigo-700 underline">المتابعة والنشاط الحالي</a>
        <p>هنا القواعد والتصحيحات المحفوظة. القواعد النشطة تدخل في مراجعة القرارات حسب نطاقها. كل تعديل له نسخة وتاريخ ومصدر، ويمكن استرجاع نسخة سابقة.</p>
        <p class="text-sm text-gray-600">قواعد التشغيل الثابتة تظل مطبّقة: Shadow Mode، واحترام صلاحيات الفريق، وعدم إعادة تشغيل طلب مسلّم أو ملغي. تعديل نص الذاكرة لا يمنح صلاحية تنفيذ.</p>
        @if(session('status'))<p role="status" class="rounded-lg bg-green-50 p-3">{{ session('status') }}</p>@endif
        @if(auth()->user()->hasPermission('order_automation.manage'))
            <div class="flex flex-wrap gap-4">
                <a href="{{ route('admin.agentic.memory.create') }}" class="rounded-lg bg-indigo-700 px-4 py-2 text-white">إضافة قاعدة أو تصحيح</a>
                <form method="POST" action="{{ route('admin.agentic.memory.import') }}">@csrf<button class="rounded-lg border px-4 py-2">إضافة قواعد مراجعة 3 نوفمبر</button></form>
            </div>
        @endif
        <div class="space-y-3">
            @forelse($memories as $memory)
                <article class="rounded-xl border bg-white p-4">
                    <a class="font-bold text-indigo-700 underline" href="{{ route('admin.agentic.memory.show',$memory) }}">{{ $memory->title }}</a>
                    <p class="whitespace-pre-line">{{ $memory->guidance }}</p>
                    <p class="mt-2 text-sm text-gray-600">{{ __('agentic.memory_status.'.$memory->status) }} · {{ __('agentic.memory_scope.'.$memory->scope_type) }} · نسخة {{ $memory->revision }} · {{ $memory->updated_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }} بتوقيت القاهرة</p>
                    <p class="text-sm">المصدر: {{ $memory->source_key ? 'مراجعة المالك' : ($memory->source_run_id ? 'تصحيح اقتراح مسجل' : 'قاعدة من الفريق') }}</p>
                </article>
            @empty <p>لم تُضف قواعد للذاكرة بعد. يمكنك إضافة قواعد مراجعتك أو كتابة تصحيح جديد.</p> @endforelse
        </div>
        {{ $memories->links() }}
    </main>
</x-admin-layout>

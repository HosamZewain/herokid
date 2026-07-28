<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">تصنيفات المصروفات</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">تصنيفات مستقلة للوارد والصادر. تعطيل التصنيف لا يحذف عملياته.</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-6 py-4 sm:py-7">
        <a href="{{ route('admin.expenses.index') }}" class="inline-flex text-sm font-black text-indigo-600">← العودة إلى المصروفات</a>

        <form method="POST" action="{{ route('admin.expenses.categories.store') }}" class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-7">
            @csrf
            <h3 class="font-black text-gray-900">إضافة تصنيف</h3>
            @if($errors->any())<div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <select name="type" required class="rounded-xl border-gray-200"><option value="expense">صادر</option><option value="income">وارد</option></select>
                <input name="name" required maxlength="255" placeholder="اسم التصنيف" class="rounded-xl border-gray-200">
                <input name="description" maxlength="2000" placeholder="وصف اختياري" class="rounded-xl border-gray-200">
                <input type="number" name="sort_order" min="0" value="0" placeholder="الترتيب" class="rounded-xl border-gray-200">
            </div>
            <button class="mt-4 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white">إضافة التصنيف</button>
        </form>

        @foreach(['income' => 'تصنيفات الوارد', 'expense' => 'تصنيفات الصادر'] as $type => $label)
            <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-7">
                <h3 class="text-lg font-black text-gray-900">{{ $label }}</h3>
                <div class="mt-5 space-y-4">
                    @foreach($categories->where('type', $type) as $category)
                        <form method="POST" action="{{ route('admin.expenses.categories.update', $category) }}" class="grid gap-3 rounded-2xl border border-gray-100 bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                            @csrf @method('PUT')
                            <label class="lg:col-span-2"><span class="mb-1 block text-xs font-black text-gray-500">الاسم</span><input name="name" required value="{{ $category->name }}" class="w-full rounded-xl border-gray-200"></label>
                            <label class="lg:col-span-2"><span class="mb-1 block text-xs font-black text-gray-500">الوصف</span><input name="description" value="{{ $category->description }}" class="w-full rounded-xl border-gray-200"></label>
                            <label><span class="mb-1 block text-xs font-black text-gray-500">الترتيب</span><input type="number" min="0" name="sort_order" value="{{ $category->sort_order }}" class="w-full rounded-xl border-gray-200"></label>
                            <div>
                                <label class="flex items-center gap-2 text-sm font-bold text-gray-600"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="rounded"> نشط</label>
                                <button class="mt-2 w-full rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white">حفظ</button>
                            </div>
                            <p class="text-xs text-gray-400 lg:col-span-6">عدد العمليات: {{ $category->transactions_count }}</p>
                        </form>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-admin-layout>

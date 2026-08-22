<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">إعدادات حالات الطلبات</h2>
            <p class="mt-1 text-sm font-bold text-gray-500">تحكم في حالات الطلب والدفع والطباعة والشحن من مكان واحد.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-900">
            <p>المعنى التشغيلي هو الذي يحدد انعكاس الحالة في المبالغ والإحصاءات والتقارير، أما المفتاح فهو مرجع تقني ثابت.</p>
            <p>حالات النظام لا تُحذف حفاظًا على الأتمتة والطلبات القديمة. ويمكن تعطيل أي حالة لإخفائها من الاختيارات الجديدة.</p>
        </div>

        @foreach($groups as $group)
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 bg-slate-50 px-5 py-4">
                    <h3 class="text-lg font-black text-gray-950">{{ $group['label'] }}</h3>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach($group['definitions'] as $definition)
                        <form method="POST" action="{{ route('admin.settings.order-statuses.update', $definition) }}" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-7 xl:items-end">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="mb-1 block text-xs font-black text-gray-600">المفتاح</label>
                                <input value="{{ $definition->key }}" disabled dir="ltr" class="w-full rounded-xl border-gray-200 bg-gray-100 text-left text-sm text-gray-500">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-black text-gray-600">الاسم الظاهر</label>
                                <input name="label_ar" value="{{ $definition->label_ar }}" required class="w-full rounded-xl border-gray-200 text-right text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-black text-gray-600">المعنى التشغيلي</label>
                                <select name="behavior" required class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    @foreach($group['behaviors'] as $key => $label)<option value="{{ $key }}" @selected($definition->behavior === $key)>{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-black text-gray-600">اللون</label>
                                <select name="color" required class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    @foreach($colors as $key => $label)<option value="{{ $key }}" @selected($definition->color === $key)>{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-black text-gray-600">الترتيب</label>
                                <input name="sort_order" type="number" min="0" max="9999" value="{{ $definition->sort_order }}" required class="w-full rounded-xl border-gray-200 text-left text-sm" dir="ltr">
                            </div>
                            <label class="flex min-h-11 items-center gap-2 rounded-xl border border-gray-200 px-3 text-sm font-black text-gray-700">
                                <input name="is_active" type="checkbox" value="1" @checked($definition->is_active) class="rounded border-gray-300 text-indigo-600">
                                نشطة
                            </label>
                            <div class="flex gap-2">
                                <button class="min-h-11 flex-1 rounded-xl bg-indigo-600 px-3 py-2 text-sm font-black text-white hover:bg-indigo-700">حفظ</button>
                                @if(! $definition->is_system)
                                    <button type="submit" form="delete-status-{{ $definition->id }}" class="min-h-11 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-black text-red-700" onclick="return confirm('حذف الحالة إن لم تكن مستخدمة، أو تعطيلها إن كانت مستخدمة؟')">حذف</button>
                                @endif
                            </div>
                            <div class="sm:col-span-2 xl:col-span-7">
                                <label class="mb-1 block text-xs font-black text-gray-600">وصف داخلي (اختياري)</label>
                                <input name="description" value="{{ $definition->description }}" maxlength="500" class="w-full rounded-xl border-gray-200 text-right text-sm" placeholder="متى يستخدم فريق العمل هذه الحالة؟">
                            </div>
                        </form>
                        @if(! $definition->is_system)
                            <form id="delete-status-{{ $definition->id }}" method="POST" action="{{ route('admin.settings.order-statuses.destroy', $definition) }}" class="hidden">@csrf @method('DELETE')</form>
                        @endif
                    @endforeach
                </div>

                <form method="POST" action="{{ route('admin.settings.order-statuses.store') }}" class="grid gap-3 border-t-2 border-dashed border-indigo-100 bg-indigo-50/40 p-4 sm:grid-cols-2 xl:grid-cols-7 xl:items-end">
                    @csrf
                    <input type="hidden" name="type" value="{{ $group['type'] }}">
                    <div>
                        <label class="mb-1 block text-xs font-black text-indigo-700">مفتاح الحالة الجديدة</label>
                        <input name="key" required maxlength="32" pattern="[a-z][a-z0-9_]*" dir="ltr" class="w-full rounded-xl border-indigo-200 text-left text-sm" placeholder="customer_confirmed">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-black text-indigo-700">الاسم الظاهر</label>
                        <input name="label_ar" required maxlength="100" class="w-full rounded-xl border-indigo-200 text-right text-sm" placeholder="أكد العميل">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-black text-indigo-700">المعنى التشغيلي</label>
                        <select name="behavior" required class="w-full rounded-xl border-indigo-200 text-right text-sm">
                            @foreach($group['behaviors'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-black text-indigo-700">اللون</label>
                        <select name="color" required class="w-full rounded-xl border-indigo-200 text-right text-sm">
                            @foreach($colors as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-black text-indigo-700">الترتيب</label>
                        <input name="sort_order" type="number" min="0" max="9999" value="100" required class="w-full rounded-xl border-indigo-200 text-left text-sm" dir="ltr">
                    </div>
                    <label class="flex min-h-11 items-center gap-2 rounded-xl border border-indigo-200 bg-white px-3 text-sm font-black text-indigo-700">
                        <input name="is_active" type="checkbox" value="1" checked class="rounded border-gray-300 text-indigo-600">
                        نشطة
                    </label>
                    <button class="min-h-11 rounded-xl bg-gray-950 px-4 py-2 text-sm font-black text-white">إضافة حالة</button>
                    <div class="sm:col-span-2 xl:col-span-7">
                        <input name="description" maxlength="500" class="w-full rounded-xl border-indigo-200 text-right text-sm" placeholder="وصف داخلي اختياري">
                    </div>
                </form>
            </section>
        @endforeach
    </div>
</x-admin-layout>

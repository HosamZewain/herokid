<x-admin-layout>
    <x-slot name="header"><div class="flex items-center justify-between"><h2 class="text-xl font-semibold text-gray-800">أقسام المتجر في الصفحة الرئيسية</h2><a href="{{ route('admin.homepage-store-sections.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white">إضافة قسم</a></div></x-slot>
    <div class="py-8" dir="rtl"><div class="mx-auto max-w-6xl space-y-4 sm:px-6 lg:px-8">
        @if(session('success'))<div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 font-bold text-green-700">{{ session('success') }}</div>@endif
        <div class="rounded-2xl bg-white shadow-sm overflow-hidden"><table class="min-w-full divide-y divide-gray-100 text-right text-sm">
            <thead class="bg-gray-50 text-xs font-bold text-gray-500"><tr><th class="px-4 py-3">العنوان</th><th class="px-4 py-3">التصنيف</th><th class="px-4 py-3">الحد</th><th class="px-4 py-3">الترتيب</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">إجراءات</th></tr></thead>
            <tbody class="divide-y divide-gray-100">@foreach($sections as $section)<tr><td class="px-4 py-3 font-black">{{ $section->title_ar }}</td><td class="px-4 py-3">{{ $section->category?->name_ar ?? '-' }}</td><td class="px-4 py-3">{{ $section->max_products }}</td><td class="px-4 py-3">{{ $section->sort_order }}</td><td class="px-4 py-3">{{ $section->is_active ? 'نشط' : 'معطل' }}</td><td class="px-4 py-3"><a href="{{ route('admin.homepage-store-sections.edit', $section) }}" class="font-bold text-indigo-600">تعديل</a></td></tr>@endforeach</tbody>
        </table></div>{{ $sections->links() }}
    </div></div>
</x-admin-layout>

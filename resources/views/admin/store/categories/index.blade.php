<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">تصنيفات المتجر</h2>
            @can('store.categories.create')
                <a href="{{ route('admin.product-categories.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">إضافة تصنيف</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
            @foreach(['success' => 'green', 'error' => 'red'] as $key => $color)
                @if(session($key))
                    <div class="rounded-xl border border-{{ $color }}-200 bg-{{ $color }}-50 px-4 py-3 font-bold text-{{ $color }}-700">{{ session($key) }}</div>
                @endif
            @endforeach
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                    <thead class="bg-gray-50 text-xs font-bold text-gray-500">
                        <tr>
                            <th class="px-4 py-3">التصنيف</th>
                            <th class="px-4 py-3">Slug</th>
                            <th class="px-4 py-3">منتجات</th>
                            <th class="px-4 py-3">الترتيب</th>
                            <th class="px-4 py-3">الحالة</th>
                            <th class="px-4 py-3">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($categories as $category)
                            <tr>
                                <td class="px-4 py-3 font-black text-gray-900">{{ $category->name_ar }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $category->slug }}</td>
                                <td class="px-4 py-3">{{ $category->products_count }}</td>
                                <td class="px-4 py-3">{{ $category->sort_order }}</td>
                                <td class="px-4 py-3">{{ $category->is_active ? 'نشط' : 'معطل' }} / {{ $category->show_in_store ? 'ظاهر' : 'مخفي' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        @can('store.categories.update')
                                            <a href="{{ route('admin.product-categories.edit', $category) }}" class="font-bold text-indigo-600">تعديل</a>
                                        @endcan
                                        @can('store.categories.delete')
                                            <form action="{{ route('admin.product-categories.destroy', $category) }}" method="POST" onsubmit="return confirm('حذف التصنيف؟')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="font-bold text-red-600">حذف</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $categories->links() }}
        </div>
    </div>
</x-admin-layout>

<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">منتجات المتجر</h2>
            <div class="flex gap-2">
                @can('store.categories.view')
                    <a href="{{ route('admin.product-categories.index') }}" class="rounded-lg border px-4 py-2 text-sm font-bold">التصنيفات</a>
                @endcan
                @can('store.homepage_sections.view')
                    <a href="{{ route('admin.homepage-store-sections.index') }}" class="rounded-lg border px-4 py-2 text-sm font-bold">أقسام الرئيسية</a>
                @endcan
                @can('store.upsell_rules.view')
                    <a href="{{ route('admin.upsell-rules.index') }}" class="rounded-lg border px-4 py-2 text-sm font-bold">Upsell</a>
                @endcan
                @can('store.products.create')
                    <a href="{{ route('admin.products.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">إضافة منتج</a>
                @endcan
            </div>
        </div>
    </x-slot>
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
            @if(session('success'))<div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 font-bold text-green-700">{{ session('success') }}</div>@endif
            <form method="GET" class="flex flex-wrap gap-3 rounded-2xl bg-white p-4 shadow-sm">
                <select name="category" class="rounded-xl border-gray-300 text-right"><option value="">كل التصنيفات</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name_ar }}</option>@endforeach</select>
                <select name="status" class="rounded-xl border-gray-300 text-right"><option value="">كل الحالات</option><option value="active" @selected(request('status') === 'active')>نشط</option><option value="inactive" @selected(request('status') === 'inactive')>معطل</option></select>
                <button class="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white">بحث</button>
            </form>
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm">
                <table class="min-w-[1100px] divide-y divide-gray-100 text-right text-sm">
                    <thead class="bg-gray-50 text-xs font-bold text-gray-500"><tr><th class="px-4 py-3">المنتج</th><th class="px-4 py-3">التصنيف</th><th class="px-4 py-3">السعر</th><th class="px-4 py-3">المشاهدات</th><th class="px-4 py-3">عدد الطلبات</th><th class="px-4 py-3">التخصيص</th><th class="px-4 py-3">المخزون</th><th class="px-4 py-3">الحالة</th><th class="px-4 py-3">إجراءات</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($products as $product)
                            <tr>
                                <td class="px-4 py-3">
                                    @can('store.products.update')
                                        <a href="{{ route('admin.products.edit', $product) }}" class="font-black text-gray-900 hover:text-indigo-600">{{ $product->name_ar }}</a>
                                    @else
                                        <span class="font-black text-gray-900">{{ $product->name_ar }}</span>
                                    @endcan
                                    <div class="text-xs text-gray-400">{{ $product->slug }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $product->category?->name_ar ?? '-' }}</td>
                                <td class="px-4 py-3">{{ number_format($product->effectivePrice(), 0) }} ج.م</td>
                                <td class="px-4 py-3 font-bold text-slate-700">{{ number_format($product->views_count) }}</td>
                                <td class="px-4 py-3 font-bold text-slate-700">{{ number_format($product->orders_count) }}</td>
                                <td class="px-4 py-3">{{ $product->personalization_mode }}</td>
                                <td class="px-4 py-3">{{ $product->inventory_mode }} @if($product->stock_quantity !== null) / {{ $product->stock_quantity }} @endif</td>
                                <td class="px-4 py-3">{{ $product->is_active ? 'نشط' : 'معطل' }}</td>
                                <td class="px-4 py-3"><a href="{{ route('shop.product.show', $product) }}" target="_blank" class="font-bold text-slate-600">معاينة</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $products->links() }}
        </div>
    </div>
</x-admin-layout>

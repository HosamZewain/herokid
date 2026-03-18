<x-admin-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">باقات الأسعار</h2>
            <a href="{{ route('admin.pricing.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                إضافة باقة جديدة
            </a>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">{{ session('success') }}</div>
            @endif

            <div class="bg-white shadow-sm rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-right text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">الترتيب</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">الباقة</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">السعر</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">مميزة</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">الحالة</th>
                            <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($packages as $package)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-400">{{ $package->sort_order }}</td>
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900">{{ $package->name }}</div>
                                @if($package->subtitle)
                                    <div class="text-xs text-gray-400">{{ $package->subtitle }}</div>
                                @endif
                                @if($package->badge)
                                    <span class="inline-block mt-1 text-[10px] bg-yellow-100 text-yellow-700 font-bold px-2 py-0.5 rounded-full">{{ $package->badge }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-bold text-indigo-600">
                                {{ number_format($package->price, 0) }} {{ $package->currency }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($package->is_featured)
                                    <span class="text-yellow-500 text-lg">⭐</span>
                                @else
                                    <span class="text-gray-200">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($package->active)
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>نشطة
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-400 inline-block"></span>مخفية
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('admin.pricing.edit', $package) }}" title="تعديل" class="text-gray-400 hover:text-indigo-600 transition">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </a>
                                    <form action="{{ route('admin.pricing.destroy', $package) }}" method="POST"
                                          onsubmit="return confirm('حذف باقة «{{ addslashes($package->name) }}»؟')">
                                        @csrf @method('DELETE')
                                        <button type="submit" title="حذف" class="text-gray-400 hover:text-red-600 transition">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                لا توجد باقات بعد. <a href="{{ route('admin.pricing.create') }}" class="text-indigo-600 hover:underline">أضف أول باقة</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-gray-400 text-right">الباقات تُعرض على صفحة الأسعار مرتبة حسب "الترتيب" من الأصغر للأكبر.</p>
        </div>
    </div>
</x-admin-layout>

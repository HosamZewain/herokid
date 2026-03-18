<x-admin-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('إدارة القصص') }}
            </h2>
            <a href="{{ route('admin.stories.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                إضافة قصة جديدة
            </a>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            {{-- Summary Cards --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-lg shadow p-4 flex items-center gap-3">
                    <div class="bg-blue-100 text-blue-600 rounded-full p-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">إجمالي القصص</p>
                        <p class="text-2xl font-bold text-gray-800">{{ $stories->total() }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 flex items-center gap-3">
                    <div class="bg-green-100 text-green-600 rounded-full p-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">نشطة</p>
                        <p class="text-2xl font-bold text-gray-800">{{ $stories->getCollection()->where('active', true)->count() }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 flex items-center gap-3">
                    <div class="bg-red-100 text-red-500 rounded-full p-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">معطلة</p>
                        <p class="text-2xl font-bold text-gray-800">{{ $stories->getCollection()->where('active', false)->count() }}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 flex items-center gap-3">
                    <div class="bg-purple-100 text-purple-600 rounded-full p-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">إجمالي الطلبات</p>
                        <p class="text-2xl font-bold text-gray-800">{{ $stories->getCollection()->sum('orders_count') }}</p>
                    </div>
                </div>
            </div>

            {{-- Search & Filter --}}
            <div class="bg-white rounded-lg shadow p-4">
                <form method="GET" action="{{ route('admin.stories.index') }}" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 mb-1">بحث بالعنوان أو slug</label>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="ابحث..." class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">الحالة</label>
                        <select name="status" class="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <option value="">الكل</option>
                            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>نشطة</option>
                            <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>معطلة</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">اللغة</label>
                        <select name="language" class="border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <option value="">الكل</option>
                            <option value="ar" {{ request('language') === 'ar' ? 'selected' : '' }}>عربي</option>
                            <option value="en" {{ request('language') === 'en' ? 'selected' : '' }}>إنجليزي</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">بحث</button>
                        <a href="{{ route('admin.stories.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">إعادة تعيين</a>
                    </div>
                </form>
            </div>

            {{-- Stories Table --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-right text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">#</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">القصة</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">التصنيفات</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">الفئة العمرية</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">اللغة</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">السعر</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">طلبات</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">مرفقات</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">الحالة</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">تاريخ الإضافة</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase">آخر تعديل</th>
                                <th class="px-4 py-3 text-xs font-semibold text-gray-500 uppercase text-center">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @forelse($stories as $index => $story)
                                <tr class="hover:bg-gray-50 transition">
                                    {{-- Row number --}}
                                    <td class="px-4 py-3 text-gray-400 text-xs">
                                        {{ $stories->firstItem() + $loop->index }}
                                    </td>

                                    {{-- Story: thumbnail + title + slug --}}
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            @if($story->cover_url)
                                                <img src="{{ $story->cover_url }}" alt="{{ $story->title }}"
                                                     class="w-12 h-12 rounded object-contain bg-gray-100 border border-gray-200 flex-shrink-0">
                                            @else
                                                <div class="w-12 h-12 rounded bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-300 flex-shrink-0">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                </div>
                                            @endif
                                            <div>
                                                <div class="font-medium text-gray-900">{{ $story->title }}</div>
                                                <div class="text-xs text-gray-400 mt-0.5">{{ $story->slug }}</div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Categories --}}
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse($story->categories as $cat)
                                                <span class="inline-block bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded-full">{{ $cat->name }}</span>
                                            @empty
                                                <span class="text-gray-300 text-xs">—</span>
                                            @endforelse
                                        </div>
                                    </td>

                                    {{-- Age Range --}}
                                    <td class="px-4 py-3 text-gray-600 whitespace-nowrap">
                                        {{ $story->age_range ?: '—' }}
                                    </td>

                                    {{-- Language --}}
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        @if($story->language === 'ar')
                                            <span class="bg-orange-50 text-orange-700 text-xs px-2 py-0.5 rounded-full font-medium">عربي</span>
                                        @else
                                            <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded-full font-medium">English</span>
                                        @endif
                                    </td>

                                    {{-- Price --}}
                                    <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-800">
                                        @if($story->price == 0)
                                            <span class="text-green-600 text-xs font-semibold">مجاني</span>
                                        @else
                                            {{ number_format($story->price, 2) }}
                                        @endif
                                    </td>

                                    {{-- Orders count --}}
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
                                            {{ $story->orders_count > 0 ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-400' }}">
                                            {{ $story->orders_count }}
                                        </span>
                                    </td>

                                    {{-- Attachments count --}}
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
                                            {{ $story->attachments->count() > 0 ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-400' }}">
                                            {{ $story->attachments->count() }}
                                        </span>
                                    </td>

                                    {{-- Status --}}
                                    <td class="px-4 py-3 text-center">
                                        @if($story->active)
                                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-700 bg-green-50 px-2 py-0.5 rounded-full">
                                                <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>نشط
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 inline-block"></span>معطل
                                            </span>
                                        @endif
                                    </td>

                                    {{-- Created at --}}
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap text-xs">
                                        {{ $story->created_at->format('Y/m/d') }}
                                    </td>

                                    {{-- Updated at --}}
                                    <td class="px-4 py-3 text-gray-400 whitespace-nowrap text-xs">
                                        {{ $story->updated_at->diffForHumans() }}
                                    </td>

                                    {{-- Actions --}}
                                    <td class="px-4 py-3 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-2">
                                            {{-- View on website --}}
                                            <a href="{{ url('/stories/' . $story->slug) }}" target="_blank"
                                               title="عرض على الموقع"
                                               class="text-gray-400 hover:text-blue-600 transition" >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                            </a>
                                            {{-- Edit --}}
                                            <a href="{{ route('admin.stories.edit', $story) }}"
                                               title="تعديل"
                                               class="text-gray-400 hover:text-indigo-600 transition">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </a>
                                            {{-- Delete --}}
                                            <form action="{{ route('admin.stories.destroy', $story) }}" method="POST"
                                                  onsubmit="return confirm('هل أنت متأكد من حذف قصة «{{ addslashes($story->title) }}»؟');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" title="حذف" class="text-gray-400 hover:text-red-600 transition">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-6 py-16 text-center">
                                        <div class="text-5xl mb-3">📚</div>
                                        <p class="text-gray-400 text-sm">لا توجد قصص حتى الآن.</p>
                                        <a href="{{ route('admin.stories.create') }}" class="mt-3 inline-block text-blue-600 hover:underline text-sm">أضف أول قصة</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($stories->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">
                        {{ $stories->withQueryString()->links() }}
                    </div>
                @endif
            </div>

        </div>
    </div>
</x-admin-layout>

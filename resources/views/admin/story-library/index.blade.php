<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">مكتبة القصص العامة</h2></x-slot>
<div class="space-y-5" dir="rtl">
    @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-emerald-900">{{ session('success') }}</p>@endif
    <div class="flex flex-wrap items-center justify-between gap-3"><p>قصص PDF كاملة للقراءة المجانية، منفصلة عن قصص البيع وملفات الأطفال.</p>@can('story_library.manage')<a href="{{ route('admin.story-library.create') }}" class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">إضافة قصة للمكتبة</a>@endcan</div>
    <a href="{{ route('story-library.index') }}" class="inline-block text-indigo-700 underline" target="_blank" rel="noopener">عرض المكتبة العامة</a>
    <div class="overflow-x-auto rounded-2xl bg-white p-4 shadow-sm"><table class="w-full text-right"><thead><tr><th class="p-3">القصة</th><th class="p-3">التصنيف / اللغة</th><th class="p-3">الملف</th><th class="p-3">الحالة</th><th class="p-3">الإجراءات</th></tr></thead><tbody>
    @forelse($stories as $story)<tr class="border-t"><td class="p-3 font-bold">{{ $story->title }}</td><td class="p-3">{{ $story->category ?: 'بدون تصنيف' }} · {{ $story->language === 'ar' ? 'عربي' : 'إنجليزي' }}</td><td class="p-3">{{ $story->page_count }} صفحة · {{ number_format($story->file_size / 1048576, 1) }} MB</td><td class="p-3">{{ $story->status === 'published' ? 'منشورة' : 'مسودة' }}</td><td class="p-3"><a class="text-indigo-700 underline" href="{{ route('admin.story-library.document', $story) }}" target="_blank" rel="noopener">فحص PDF</a>@can('story_library.manage') · <a class="text-indigo-700 underline" href="{{ route('admin.story-library.edit', $story) }}">تعديل</a>@endcan @if($story->status === 'published') · <a class="text-indigo-700 underline" href="{{ route('story-library.read', $story) }}">قراءة</a>@endif</td></tr>@empty<tr><td colspan="5" class="p-6 text-center">المكتبة فارغة. ابدأ برفع قصة كاملة.</td></tr>@endforelse
    </tbody></table></div>{{ $stories->links() }}
</div>
</x-admin-layout>

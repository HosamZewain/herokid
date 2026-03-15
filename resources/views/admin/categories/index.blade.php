<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">تصنيفات القصص</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Add New Category --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">إضافة تصنيف جديد</h3>
                <form action="{{ route('admin.categories.store') }}" method="POST" class="flex gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-text-input
                            name="name"
                            class="block w-full"
                            type="text"
                            placeholder="مثال: مغامرات، حيوانات، أبطال خارقون..."
                            :value="old('name')"
                            required
                        />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <x-primary-button>إضافة</x-primary-button>
                </form>
            </div>

            {{-- Categories List --}}
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">التصنيف</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Slug</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">عدد القصص</th>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($categories as $category)
                        <tr>
                            <td class="px-6 py-4 font-semibold text-gray-900 text-right">{{ $category->name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500 text-right font-mono">{{ $category->slug }}</td>
                            <td class="px-6 py-4 text-right">
                                <span class="bg-indigo-100 text-indigo-800 text-xs font-bold px-2.5 py-1 rounded-full">{{ $category->stories_count }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <form action="{{ route('admin.categories.destroy', $category) }}" method="POST"
                                      onsubmit="return confirm('هل أنت متأكد من حذف هذا التصنيف؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-bold">حذف</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-gray-400">
                                لا توجد تصنيفات بعد. أضف تصنيفك الأول من الأعلى.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-admin-layout>

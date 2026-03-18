<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.pricing.index') }}" class="text-gray-400 hover:text-gray-600">← رجوع</a>
            <h2 class="font-semibold text-xl text-gray-800">تعديل باقة: {{ $package->name }}</h2>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-xl p-8">
                <form action="{{ route('admin.pricing.update', $package) }}" method="POST" class="space-y-5">
                    @csrf @method('PUT')
                    @include('admin.pricing._form', ['package' => $package])
                    <div class="flex gap-3 pt-2">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-lg transition">
                            💾 حفظ التغييرات
                        </button>
                        <a href="{{ route('admin.pricing.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-lg transition">إلغاء</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>

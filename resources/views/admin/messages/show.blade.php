<x-admin-layout>
    <div class="mb-6 flex justify-between items-center">
        <h2 class="text-2xl font-bold border-r-4 border-indigo-600 pr-3">عرض الرسالة</h2>
        <a href="{{ route('admin.messages.index') }}" class="text-indigo-600 hover:text-indigo-900">العودة للرسائل</a>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
        <div class="bg-gray-50 border-b px-6 py-4 flex justify-between items-center">
            <div>
                <h3 class="text-lg font-bold text-gray-900">{{ $message->subject }}</h3>
                <p class="text-sm text-gray-500 mt-1">من: {{ $message->name }} &lt;<a href="mailto:{{ $message->email }}" class="text-indigo-600 dir-ltr inline-block">{{ $message->email }}</a>&gt;</p>
            </div>
            <div class="text-sm text-gray-500">
                <p>{{ $message->created_at->format('Y/m/d') }}</p>
                <p dir="ltr" class="text-right">{{ $message->created_at->format('H:i') }}</p>
            </div>
        </div>
        
        <div class="p-6">
            <div class="prose max-w-none text-gray-800 whitespace-pre-wrap">{{ $message->message }}</div>
        </div>
        
        <div class="bg-gray-50 px-6 py-4 border-t flex justify-end">
            <a href="mailto:{{ $message->email }}?subject=RE: {{ $message->subject }}" class="bg-indigo-600 text-white px-4 py-2 rounded shadow hover:bg-indigo-700 ml-3 text-sm">رد عبر البريد</a>
            
            <form action="{{ route('admin.messages.destroy', $message) }}" method="POST">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-50 text-red-600 border border-red-200 px-4 py-2 rounded hover:bg-red-100 text-sm" onclick="return confirm('هل أنت متأكد من حذف هذه الرسالة؟')">حذف الرسالة</button>
            </form>
        </div>
    </div>
</x-admin-layout>

<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.activity-logs.index') }}" class="text-gray-400 hover:text-gray-600">← رجوع</a>
            <div>
                <h2 class="text-xl font-bold text-gray-800">تفاصيل سجل النشاط #{{ $activityLog->id }}</h2>
                <p class="text-xs text-gray-500 mt-1">{{ $activityLog->created_at->format('Y-m-d H:i:s') }}</p>
            </div>
        </div>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-extrabold text-gray-900 mb-4">بيانات الإجراء</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-400 font-bold">الإجراء</dt>
                        <dd class="text-gray-900 font-extrabold">{{ $activityLog->action }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400 font-bold">المشرف</dt>
                        <dd class="text-gray-900 font-semibold">{{ $activityLog->user?->name ?? 'غير معروف' }}</dd>
                        <dd class="text-gray-500 text-xs">{{ $activityLog->user?->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400 font-bold">المسار</dt>
                        <dd class="text-gray-900">{{ $activityLog->route_name ?: 'غير متاح' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400 font-bold">الطريقة</dt>
                        <dd class="text-gray-900">{{ $activityLog->method ?: 'غير متاح' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400 font-bold">IP</dt>
                        <dd class="text-gray-900">{{ $activityLog->ip_address ?: 'غير متاح' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-extrabold text-gray-900 mb-4">العنصر المرتبط</h3>
                @if($activityLog->subject_type && $activityLog->subject_id)
                    <p class="text-sm text-gray-600">{{ class_basename($activityLog->subject_type) }} #{{ $activityLog->subject_id }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ $activityLog->subject_type }}</p>
                @else
                    <p class="text-sm text-gray-500">لا يوجد عنصر مرتبط.</p>
                @endif
            </div>
        </div>

        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-extrabold text-gray-900 mb-3">الوصف</h3>
                <p class="text-gray-700 leading-relaxed">{{ $activityLog->description ?: 'بدون وصف' }}</p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-extrabold text-gray-900 mb-3">تفاصيل إضافية</h3>
                <pre dir="ltr" class="bg-slate-950 text-slate-100 rounded-xl p-4 overflow-auto text-xs leading-relaxed text-left">{{ json_encode($activityLog->properties ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-extrabold text-gray-900 mb-3">بيانات الطلب</h3>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-400 font-bold">الرابط</dt>
                        <dd dir="ltr" class="text-left break-all text-gray-700">{{ $activityLog->url ?: 'غير متاح' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-400 font-bold">المتصفح</dt>
                        <dd dir="ltr" class="text-left break-all text-gray-700">{{ $activityLog->user_agent ?: 'غير متاح' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</x-admin-layout>

<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">استوديو الإنتاج</h2>
    </x-slot>

    <div class="space-y-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="text-right">
                <p class="text-sm font-black text-indigo-600">Production Studio</p>
                <h1 class="text-2xl font-black text-gray-950">مشاريع استوديو الإنتاج</h1>
                <p class="mt-2 text-sm text-gray-500">مشاريع اختيارية معزولة يتم إنشاؤها يدويًا من صفحة الطلب.</p>
            </div>
            <a href="{{ route('admin.orders.index') }}" class="inline-flex items-center justify-center rounded-xl border border-indigo-200 bg-white px-5 py-3 text-sm font-black text-indigo-700 hover:bg-indigo-50">
                الذهاب إلى الطلبات
            </a>
        </div>

        <form method="GET" class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <input name="search" value="{{ request('search') }}" placeholder="بحث برقم الطلب / العميل / الطفل" class="rounded-xl border-gray-300 text-right">
                <select name="status" class="rounded-xl border-gray-300 text-right">
                    <option value="">كل الحالات</option>
                    @foreach($statuses as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="current_stage" class="rounded-xl border-gray-300 text-right">
                    <option value="">كل المراحل</option>
                    @foreach($stages as $value => $label)
                        <option value="{{ $value }}" @selected(request('current_stage') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="assigned_to_user_id" class="rounded-xl border-gray-300 text-right">
                    <option value="">كل المسؤولين</option>
                    @foreach($assignees as $assignee)
                        <option value="{{ $assignee->id }}" @selected((string) request('assigned_to_user_id') === (string) $assignee->id)>{{ $assignee->name }}</option>
                    @endforeach
                </select>
                <select name="story_id" class="rounded-xl border-gray-300 text-right">
                    <option value="">كل القصص</option>
                    @foreach($stories as $story)
                        <option value="{{ $story->id }}" @selected((string) request('story_id') === (string) $story->id)>{{ $story->title }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-xl border-gray-300 text-right">
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-xl border-gray-300 text-right">
                <div class="flex gap-2">
                    <button class="flex-1 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">بحث</button>
                    <a href="{{ route('admin.production-studio.index') }}" class="rounded-xl bg-gray-100 px-4 py-2 text-sm font-black text-gray-700 hover:bg-gray-200">إعادة</a>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
            @if($projects->isEmpty())
                <div class="p-10 text-center">
                    <p class="text-lg font-black text-gray-900">لا توجد مشاريع داخل استوديو الإنتاج بعد.</p>
                    <p class="mx-auto mt-3 max-w-2xl text-sm leading-7 text-gray-500">
                        القائمة فارغة افتراضيًا. يتم إنشاء مشروع فقط عندما يفتح المشرف صفحة طلب ويضغط “إرسال إلى استوديو الإنتاج”.
                    </p>
                    <a href="{{ route('admin.orders.index') }}" class="mt-6 inline-flex rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">فتح قائمة الطلبات</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50 text-xs font-black text-gray-500">
                            <tr>
                                <th class="px-4 py-3">المشروع</th>
                                <th class="px-4 py-3">رقم الطلب</th>
                                <th class="px-4 py-3">الطفل</th>
                                <th class="px-4 py-3">القصة</th>
                                <th class="px-4 py-3">الحالة</th>
                                <th class="px-4 py-3">المرحلة</th>
                                <th class="px-4 py-3">المسؤول</th>
                                <th class="px-4 py-3">تاريخ الإنشاء</th>
                                <th class="px-4 py-3">آخر تحديث</th>
                                <th class="px-4 py-3">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($projects as $project)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono">#{{ $project->id }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.orders.show', $project->order) }}" class="font-black text-indigo-600 hover:underline">{{ $project->order->order_number }}</a>
                                    </td>
                                    <td class="px-4 py-3 font-bold">{{ $project->order->child_name ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $project->order->story?->title ?? '-' }}</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $project->statusLabel() }}</span></td>
                                    <td class="px-4 py-3">{{ $project->stageLabel() }}</td>
                                    <td class="px-4 py-3">{{ $project->assignedTo?->name ?? 'غير معين' }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $project->created_at?->format('Y-m-d') }}</td>
                                    <td class="px-4 py-3 text-gray-500">{{ $project->updated_at?->diffForHumans() }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.production-studio.show', $project) }}" class="font-black text-indigo-600 hover:underline">فتح</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $projects->links() }}</div>
            @endif
        </div>
    </div>
</x-admin-layout>

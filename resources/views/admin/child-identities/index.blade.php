<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-slate-900">هويات الأطفال</h2>
            <p class="mt-1 text-xs text-slate-500">كل الطلبات الدائمة، بما فيها غير المكتملة والفاشلة وغير المحولة.</p>
        </div>
    </x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>
            @endif

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                @foreach([
                    ['النشطة', $stats['active'], 'border-indigo-100', 'text-indigo-700'],
                    ['غير مكتملة', $stats['incomplete'], 'border-amber-100', 'text-amber-700'],
                    ['جاهزة/معتمدة', $stats['generated'], 'border-violet-100', 'text-violet-700'],
                    ['تحولت لطلبات', $stats['converted'], 'border-emerald-100', 'text-emerald-700'],
                    ['المحذوفات', $stats['trash'], 'border-slate-200', 'text-slate-700'],
                ] as [$label, $value, $borderClass, $textClass])
                    <div class="rounded-2xl border {{ $borderClass }} bg-white p-4 shadow-sm">
                        <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-2xl font-black {{ $textClass }}">{{ arabic_number($value) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex gap-2">
                        <a href="{{ route('admin.child-identities.index') }}" class="rounded-xl px-4 py-2 text-sm font-black {{ !$trash ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600' }}">الطلبات النشطة</a>
                        <a href="{{ route('admin.child-identities.trash') }}" class="rounded-xl px-4 py-2 text-sm font-black {{ $trash ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600' }}">سلة المحذوفات</a>
                    </div>
                    @can('child_identities.settings')
                        <a href="{{ route('admin.child-identities.settings.edit') }}" class="rounded-xl border border-indigo-200 px-4 py-2 text-sm font-black text-indigo-700">الإعدادات</a>
                    @endcan
                </div>

                <form method="GET" action="{{ $trash ? route('admin.child-identities.trash') : route('admin.child-identities.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-7">
                    <input name="q" value="{{ request('q') }}" placeholder="UUID، طفل، ولي أمر، هاتف، طلب" class="rounded-xl border-slate-300 text-sm xl:col-span-2">
                    <select name="status" class="rounded-xl border-slate-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(\App\Models\ChildIdentityRequest::STATUSES as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Models\ChildIdentityRequest::statusLabelFor($status) }}</option>
                        @endforeach
                    </select>
                    <select name="conversion" class="rounded-xl border-slate-300 text-sm">
                        <option value="">التحويل: الكل</option>
                        <option value="converted" @selected(request('conversion') === 'converted')>محول لطلب</option>
                        <option value="not_converted" @selected(request('conversion') === 'not_converted')>غير محول</option>
                    </select>
                    <select name="model" class="rounded-xl border-slate-300 text-sm">
                        <option value="">كل النماذج</option>
                        @foreach($models as $model)
                            <option value="{{ $model }}" @selected(request('model') === $model)>{{ $model }}</option>
                        @endforeach
                    </select>
                    <select name="outcome" class="rounded-xl border-slate-300 text-sm">
                        <option value="">كل النتائج</option>
                        <option value="success" @selected(request('outcome') === 'success')>بها نجاح</option>
                        <option value="failure" @selected(request('outcome') === 'failure')>بها فشل</option>
                    </select>
                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white">تطبيق</button>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-xl border-slate-300 text-sm">
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-xl border-slate-300 text-sm">
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full divide-y divide-slate-100 text-right text-sm">
                        <thead class="bg-slate-50 text-xs font-black text-slate-500">
                            <tr>
                                <th class="px-5 py-4">الطلب / الطفل</th>
                                <th class="px-5 py-4">ولي الأمر</th>
                                <th class="px-5 py-4">الحالة</th>
                                <th class="px-5 py-4">الوسائط والمحاولات</th>
                                <th class="px-5 py-4">القصة / الطلب</th>
                                <th class="px-5 py-4">التاريخ</th>
                                <th class="px-5 py-4"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($identities as $identity)
                                @php($latestAttempt = $identity->attempts->first())
                                <tr class="hover:bg-slate-50">
                                    <td class="px-5 py-4">
                                        <p class="font-black text-slate-900">{{ $identity->child_name }}، {{ arabic_number($identity->child_age) }} سنوات</p>
                                        <p class="mt-1 max-w-48 truncate text-xs text-slate-400" dir="ltr">{{ $identity->uuid }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-slate-700">{{ $identity->parent_name }}</p>
                                        <p class="mt-1 text-xs text-slate-400" dir="ltr">{{ $identity->parent_phone }}</p>
                                    </td>
                                    <td class="px-5 py-4"><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $identity->statusLabel() }}</span></td>
                                    <td class="px-5 py-4">
                                        <p>{{ arabic_number($identity->photos_count) }} صور • {{ arabic_number($identity->attempts_count) }} محاولات</p>
                                        <p class="mt-1 text-xs text-slate-400">{{ $latestAttempt?->model }} {{ $latestAttempt ? '• '.$latestAttempt->statusLabel() : '' }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p>{{ $identity->selectedStory?->title ?: 'لم تُحدد قصة' }}</p>
                                        @if($identity->convertedOrder)
                                            <a href="{{ route('admin.orders.show', $identity->convertedOrder) }}" class="mt-1 block text-xs font-black text-indigo-600">#{{ $identity->convertedOrder->order_number }}</a>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 text-slate-500">{{ app_datetime($identity->created_at, 'd/m/Y H:i') }}</td>
                                    <td class="px-5 py-4"><a href="{{ route('admin.child-identities.show', $identity->id) }}" class="font-black text-indigo-600">عرض ←</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-slate-100 lg:hidden">
                    @foreach($identities as $identity)
                        <a href="{{ route('admin.child-identities.show', $identity->id) }}" class="block space-y-3 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div><p class="font-black text-slate-900">{{ $identity->child_name }}</p><p class="text-xs text-slate-500">{{ $identity->parent_name }} • {{ $identity->parent_phone }}</p></div>
                                <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $identity->statusLabel() }}</span>
                            </div>
                            <div class="flex justify-between text-xs text-slate-500">
                                <span>{{ arabic_number($identity->photos_count) }} صور • {{ arabic_number($identity->attempts_count) }} محاولات</span>
                                <span>{{ app_datetime($identity->created_at, 'd/m/Y') }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>

                @if($identities->isEmpty())
                    <div class="p-12 text-center text-slate-500">لا توجد طلبات مطابقة.</div>
                @endif
            </div>

            {{ $identities->links() }}
        </div>
    </div>
</x-admin-layout>

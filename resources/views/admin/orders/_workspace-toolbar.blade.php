<section class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm" data-orders-workspace data-progress-url="{{ route('admin.orders.workspace.progress') }}" data-total="{{ $groups->total() }}">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><h3 class="font-black text-gray-900">متابعة التنفيذ والإسناد</h3><span class="text-xs text-gray-500" data-workspace-updated>يتحدث كل 10 ثوانٍ</span></div>
    <nav class="flex flex-wrap gap-2" aria-label="وضع تنفيذ الطلبات">
        @foreach(['all'=>'الكل','automation'=>'🤖 في الأوتوميشن','team'=>'مع الفريق','attention'=>'⚠ يحتاج تدخل'] as $tab=>$label)
            <a href="{{ route('admin.orders.index',array_merge(request()->except('page'),['workspace'=>$tab])) }}" @if(request('workspace','all') === $tab) aria-current="page" @endif class="rounded-xl px-4 py-3 text-sm font-black {{ request('workspace','all') === $tab ? 'bg-indigo-600 text-white' : ($tab === 'attention' ? 'bg-red-50 text-red-800' : 'bg-gray-100 text-gray-700') }}">{{ $label }} <span data-workspace-count="{{ $tab }}">{{ $workspaceCounts[$tab] }}</span></a>
        @endforeach
    </nav>
    <div hidden data-workspace-refresh class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900">تغيرت الطلبات المطابقة للفلاتر. الترتيب والتحديد محفوظان. <button type="button" onclick="location.reload()" class="underline">تحديث القائمة</button></div>
    @can('orders.assignment.manage')
        <details class="mt-4"><summary class="cursor-pointer text-sm font-bold text-indigo-800">إسناد الطلبات المحددة</summary>
            <form method="POST" action="{{ route('admin.orders.workspace.assign') }}" data-workspace-bulk class="mt-3 flex flex-wrap gap-2">@csrf
                <select name="target_id" aria-label="الموظف للطلبات المحددة" class="rounded-xl border-gray-200 text-sm"><option value="">إزالة الإسناد</option>@foreach($assignmentUsers as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select>
                <input name="reason" required maxlength="1000" placeholder="سبب الإسناد أو النقل" aria-label="سبب الإسناد الجماعي" class="rounded-xl border-gray-200 text-sm">
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">تطبيق على المحدد</button>
                <span data-assignment-payload></span>
            </form>
        </details>
    @endcan
    @can('orders.visibility.manage')
        <details class="mt-4"><summary class="cursor-pointer text-xs font-bold text-gray-600">وضع الرؤية: {{ ($settings['orders_visibility'] ?? 'all') === 'assigned' ? 'المسند لكل موظف فقط' : 'الجميع يرى الجميع' }}</summary>
            <p class="mt-2 text-xs text-gray-500">في الوضع المقيّد، الطلبات غير المسندة يراها أصحاب صلاحية مشاهدة الجميع. التقارير العامة وشاشات العملاء المشتركة تحتاج نفس الصلاحية.</p>
            <form method="POST" action="{{ route('admin.orders.workspace.visibility') }}" class="mt-2 flex flex-wrap gap-2">@csrf<input type="hidden" name="expected" value="{{ $settings['orders_visibility'] ?? 'all' }}">
                <select name="mode" aria-label="وضع رؤية الطلبات" class="rounded-xl border-gray-200 text-sm"><option value="all" @selected(($settings['orders_visibility'] ?? 'all') === 'all')>الجميع يرى الجميع</option><option value="assigned" @selected(($settings['orders_visibility'] ?? 'all') === 'assigned')>كل موظف يرى المسند إليه فقط</option></select>
                <button class="rounded-xl border border-indigo-300 px-4 py-2 text-sm font-bold text-indigo-700">حفظ وضع الرؤية</button>
            </form>
        </details>
    @endcan
</section>
@vite('resources/js/order-workspace.js')

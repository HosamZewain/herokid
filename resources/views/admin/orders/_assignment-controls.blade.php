@php
    $assignedAdmin = $group['assigned_admin'] ?? null;
    $summary = $group['automation_summary'] ?? app(\App\Services\Orders\OrderAutomationSummary::class)->forKeys([$group['key']])[$group['key']];
    $canControl = auth()->user()->hasPermission('orders.automation.control') && auth()->user()->hasPermission('orders.update') && ! auth()->user()->agent_api_enabled
        && (auth()->user()->hasPermission('orders.view_all') || $assignedAdmin?->id === auth()->id());
@endphp
<div @if(!($compact ?? false)) id="order-automation-controls" @endif class="space-y-2" data-assignment-block="{{ $group['representative_id'] }}" data-assignee-id="{{ $assignedAdmin?->id }}" data-assignment-version="{{ $summary['assignment_version'] }}">
    <span class="inline-flex rounded-full bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-900">👤 <span data-assignee-name="{{ $group['representative_id'] }}">{{ $assignedAdmin?->name ?? 'غير مسند' }}</span> @if($assignedAdmin?->id === auth()->id()) (أنت) @endif</span>
    @if(!($group['trashed'] ?? false) && !auth()->user()->agent_api_enabled)
        @can('orders.assignment.manage')
        <details><summary class="cursor-pointer text-xs font-bold text-indigo-700">تغيير الموظف المسؤول</summary>
            <form method="POST" action="{{ route('admin.orders.workspace.assign') }}" class="mt-2 space-y-2">
                @csrf
                <input type="hidden" name="orders[0][id]" value="{{ $group['representative_id'] }}">
                <input type="hidden" name="orders[0][assignee]" value="{{ $assignedAdmin?->id }}">
                <input type="hidden" name="orders[0][version]" value="{{ $summary['assignment_version'] }}">
                <select name="target_id" aria-label="الموظف المسؤول" class="w-full rounded-lg border-gray-200 text-xs"><option value="">إزالة الإسناد</option>
                    @foreach(app(\App\Services\Orders\OrderAccess::class)->eligibleUsers() as $employee)<option value="{{ $employee->id }}" @selected($assignedAdmin?->id === $employee->id)>{{ $employee->name }}</option>@endforeach
                </select>
                <input required name="reason" maxlength="1000" placeholder="سبب تغيير الإسناد" aria-label="سبب تغيير الإسناد" class="w-full rounded-lg border-gray-200 text-xs">
                <button class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white">حفظ الإسناد</button>
            </form>
        </details>
        @endcan
        @if($canControl)
            <details><summary class="cursor-pointer text-xs font-bold text-amber-800">التحكم في الأوتوميشن</summary>
                @if(in_array($summary['control_state'], ['pause_requested','pause_uncertain'], true))
                    <p class="mt-2 text-xs font-bold text-red-800">{{ $summary['control_state'] === 'pause_requested' ? 'طلب الإيقاف مسجل؛ ننتظر حفظ الخطوة الجارية.' : 'لم يتأكد توقف العامل؛ يلزم مراجعة التنفيذ.' }}</p>
                @else
                    <form method="POST" action="{{ $summary['control_state'] === 'paused_legacy' ? route('admin.orders.groups.assignment.automation',$group['representative_id']) : route('admin.orders.workspace.control',$group['representative_id']) }}" class="mt-2 space-y-2">
                        @csrf
                        <input type="hidden" name="version" value="{{ $summary['control_version'] }}">
                        <input type="hidden" name="action" value="{{ in_array($summary['control_state'],['paused','paused_legacy'],true) ? 'resume' : 'pause' }}">
                        @if($summary['control_state'] === 'running')
                            <input required name="reason" maxlength="1000" placeholder="سبب طلب الإيقاف" aria-label="سبب طلب الإيقاف" class="w-full rounded-lg border-gray-200 text-xs">
                        @else
                            <p class="text-xs text-gray-600">لو أضفت منتجات بعد المعاينة، أكد بيانات الطلب المعدّل واحفظ كل عناصره «طلب جديد مؤكد» أولًا. الاستئناف يحفظ الصور المطابقة، ويجهّز المضاف ثم يرسل معاينة جديدة؛ الرد القديم لا يعتمد الإضافات.</p>
                        @endif
                        <button class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">{{ in_array($summary['control_state'],['paused','paused_legacy'],true) ? 'استئناف الأوتوميشن' : 'طلب إيقاف آمن' }}</button>
                    </form>
                @endif
            </details>
        @endif
    @endif
</div>

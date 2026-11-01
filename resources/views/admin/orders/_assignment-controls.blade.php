@php
    $assignedAdmin = $group['assigned_admin'] ?? null;
    $isMine = $assignedAdmin && $assignedAdmin->id === auth()->id();
    $compact ??= false;
@endphp

<div class="{{ $compact ? 'space-y-1.5' : 'flex flex-wrap items-center gap-2' }}">
    @if($group['automation_assigned'] ?? false)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-700" title="الطلب تحت متابعة الأوتوميشن حتى تسليمه للتيم">🤖 Automation</span>
    @elseif($assignedAdmin)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-3 py-1.5 text-xs font-black text-sky-800" title="تم الاستلام {{ app_datetime($group['assigned_at']) }}">
            <span aria-hidden="true">👤</span>
            {{ $assignedAdmin->name }} @if($isMine)<span class="opacity-70">(أنت)</span>@endif
        </span>

        @can('orders.assign')
            @if(!auth()->user()->agent_api_enabled && $isMine && !($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.release', $group['representative_id']) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[11px] font-black text-gray-600 hover:bg-gray-50">ترك الطلب</button>
                </form>
            @endif
        @endcan

        @can('orders.assignment.manage')
            @if(!auth()->user()->agent_api_enabled && !$isMine && !($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.takeover', $group['representative_id']) }}" class="inline" onsubmit="return confirm('الطلب مستلم بواسطة {{ addslashes($assignedAdmin->name) }}. هل تريد نقل المسؤولية إليك؟')">
                    @csrf
                    <button class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-black text-amber-800 hover:bg-amber-100">نقل إليّ</button>
                </form>
            @endif
        @endcan
    @else
        <span class="inline-flex rounded-full bg-gray-100 px-3 py-1.5 text-xs font-black text-gray-500">غير مستلم</span>
        @can('orders.assign')
            @if(!auth()->user()->agent_api_enabled && !($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.acquire', $group['representative_id']) }}" class="inline">
                    @csrf
                    <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-black text-white shadow-sm hover:bg-indigo-700">استلام الطلب</button>
                </form>
            @endif
        @endcan
    @endif
    @can('orders.assignment.manage')
        @if(auth()->user()->hasPermission('orders.update') && !auth()->user()->agent_api_enabled && !($group['trashed'] ?? false))
            @if($group['automation_manual_hold'] ?? false)
                <span class="text-xs font-bold text-amber-800">الأوتوميشن متوقف للعمل اليدوي</span>
                <form method="POST" action="{{ route('admin.orders.groups.assignment.automation', $group['representative_id']) }}" class="inline" onsubmit="return confirm('إعادة متابعة المسار السابق؟ سيتم التحقق من عدم تغيّر بياناته ولن تعاد الخطوات المكتملة.')">
                    @csrf<input type="hidden" name="action" value="resume">
                    <button class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-800">إرجاع للأوتوميشن</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.orders.groups.assignment.automation', $group['representative_id']) }}" class="inline" onsubmit="return confirm('استلام الطلب وإيقاف متابعته الآلية للعمل اليدوي؟ المحاولات الجارية يجب أن تنتهي أولًا. يمكنك بعد ذلك استخدام زر Codex للهوية المعتمدة والمدفوعة.')">
                    @csrf<input type="hidden" name="action" value="take">
                    <button class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">استلام يدوي وإيقاف الأوتوميشن</button>
                </form>
            @endif
        @endif
    @endcan
</div>

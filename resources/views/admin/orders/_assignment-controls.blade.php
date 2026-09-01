@php
    $assignedAdmin = $group['assigned_admin'] ?? null;
    $isMine = $assignedAdmin && $assignedAdmin->id === auth()->id();
    $compact ??= false;
@endphp

<div class="{{ $compact ? 'space-y-1.5' : 'flex flex-wrap items-center gap-2' }}">
    @if($assignedAdmin)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-3 py-1.5 text-xs font-black text-sky-800" title="تم الاستلام {{ app_datetime($group['assigned_at']) }}">
            <span aria-hidden="true">👤</span>
            {{ $assignedAdmin->name }} @if($isMine)<span class="opacity-70">(أنت)</span>@endif
        </span>

        @can('orders.assign')
            @if($isMine && !($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.release', $group['representative_id']) }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[11px] font-black text-gray-600 hover:bg-gray-50">ترك الطلب</button>
                </form>
            @endif
        @endcan

        @can('orders.assignment.manage')
            @if(!$isMine && !($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.takeover', $group['representative_id']) }}" class="inline" onsubmit="return confirm('الطلب مستلم بواسطة {{ addslashes($assignedAdmin->name) }}. هل تريد نقل المسؤولية إليك؟')">
                    @csrf
                    <button class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-black text-amber-800 hover:bg-amber-100">نقل إليّ</button>
                </form>
            @endif
        @endcan
    @else
        <span class="inline-flex rounded-full bg-gray-100 px-3 py-1.5 text-xs font-black text-gray-500">غير مستلم</span>
        @can('orders.assign')
            @if(!($group['trashed'] ?? false))
                <form method="POST" action="{{ route('admin.orders.groups.assignment.acquire', $group['representative_id']) }}" class="inline">
                    @csrf
                    <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-black text-white shadow-sm hover:bg-indigo-700">استلام الطلب</button>
                </form>
            @endif
        @endcan
    @endif
</div>

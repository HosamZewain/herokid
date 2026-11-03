@php
    $summary = $group['automation_summary'] ?? app(\App\Services\Orders\OrderAutomationSummary::class)->forKeys([$group['key']])[$group['key']];
    $tones = ['intervention'=>'border-red-300 bg-red-50 text-red-900','running'=>'border-blue-200 bg-blue-50 text-blue-900','waiting'=>'border-amber-200 bg-amber-50 text-amber-900','queued'=>'border-gray-200 bg-gray-50 text-gray-700','idle'=>'border-gray-200 bg-gray-50 text-gray-700'];
@endphp
<div data-automation-summary="{{ $group['representative_id'] }}" data-state="{{ $summary['state'] }}" class="rounded-xl border p-3 text-xs {{ $tones[$summary['state']] }}">
    <p class="font-black">{{ $summary['state'] === 'intervention' ? '⚠' : ($summary['tracking'] ? '🤖' : '👤') }} {{ $summary['label'] }}</p>
    @if($summary['reason'])<p class="mt-1 break-words">{{ $summary['reason'] }}</p>@endif
    @if($summary['running'] && $summary['state'] === 'intervention')<p class="mt-1 font-bold text-blue-800">يوجد توليد جارٍ أيضًا؛ انتظر تأكيد الإيقاف قبل التعديل.</p>@endif
    <details class="mt-2" data-summary-details><summary class="cursor-pointer font-bold">تفاصيل المراحل ({{ count($summary['parts']) }})</summary>
        @foreach($summary['parts'] as $part)
            <div class="mt-2 border-t pt-2"><p>{{ $part['child'] }} — {{ $part['label'] }}</p>
                @if($part['reason'])<p>{{ $part['reason'] }}</p>@endif
                @if($part['total'])<progress class="mt-1 w-full" max="{{ $part['total'] }}" value="{{ $part['done'] }}" aria-label="{{ $part['label'] }}"></progress>@endif
            </div>
        @endforeach
    </details>
    <p class="mt-2 text-[10px]">آخر نشاط: {{ $summary['updated_at'] ? app_datetime($summary['updated_at']) : '—' }}</p>
</div>

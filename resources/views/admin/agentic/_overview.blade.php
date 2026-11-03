<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach(['active'=>'الطلبات النشطة', 'unobserved'=>'لم تُقرأ بعد', 'unevaluated'=>'تقييم غير مكتمل أو تغيّر', 'covered'=>'اكتملت تغطية النسخة المرصودة'] as $key=>$label)
        <div class="rounded-xl border bg-white p-4"><p class="text-sm text-gray-600">{{ $label }}</p><strong class="text-3xl">{{ $counts[$key] }}</strong></div>
    @endforeach
</div>
<div class="my-4 space-y-1 text-sm text-gray-700">
    <p>الرصد: {{ config('agentic.observe') ? 'مفعّل' : 'متوقف' }} · التقييم: {{ config('agentic.evaluate') ? 'مفعّل' : 'متوقف' }} · المزوّد: {{ config('agentic.provider') }}</p>
    <p>محاولات اليوم بتوقيت القاهرة: <bdi dir="ltr">{{ $control->quota_day === now('Africa/Cairo')->toDateString() ? $control->attempts : 0 }} / {{ config('agentic.daily_limit') }}</bdi> · آخر دورة مسح: {{ $control->reconciled_at ?: 'لم تبدأ' }}</p>
    <p>حالة المزوّد: {{ $control->provider_error ?: 'لا يوجد خطأ مسجل؛ هذا ليس اختبار اتصال' }}. اكتمال التغطية يخص آخر نسخة مرصودة، وقد تتأخر التغييرات المباشرة حتى دورة المسح التالية.</p>
</div>
<div class="overflow-x-auto rounded-xl border bg-white">
    <table class="min-w-full text-right text-sm">
        <thead class="bg-gray-50"><tr>@foreach(['الطلب وحالته الحالية','التغطية والرصد','آخر اقتراح','التحقق والمحاكاة'] as $heading)<th scope="col" class="p-4">{{ $heading }}</th>@endforeach</tr></thead>
        <tbody class="divide-y">
            @forelse($rows as $row)
                @php($decision = $row->latest?->decision)
                <tr>
                    <td class="p-4"><a class="font-bold text-indigo-700 underline" href="{{ route('admin.agentic.show',$row->representative_id) }}">{{ $row->reference }}</a><p>{{ implode(' / ', $row->order_states) }}</p><a class="text-indigo-700 underline" href="{{ route('admin.orders.show',$row->representative_id) }}">صفحة الطلب الحالية</a></td>
                    <td class="p-4"><p>{{ __('agentic.states.'.($row->status ?: 'unobserved')) }} @if($row->dirty_at) · تغيير ينتظر الرصد @endif</p><p><bdi dir="ltr">{{ $row->covered_count }} / {{ $row->target_count ?? 0 }}</bdi> وحدات قرار · نسخة {{ $row->context_version ?? 0 }}</p><p class="text-xs">رصد: {{ $row->observed_at ?: 'لم يُقرأ' }}<br>تقييم: {{ $row->evaluated_at ?: 'لم يُقيّم' }}</p></td>
                    <td class="p-4">@if($decision)<strong>{{ __('agentic.decisions.'.$decision->decision['decision']) }}</strong><p>{{ $decision->decision['action'] ? __('agentic.actions.'.$decision->decision['action']) : __('agentic.reasons.'.$decision->decision['reason_code']) }}</p><p>{{ $row->latest->provider === 'mock' ? __('agentic.reasons.'.$decision->decision['reason_code']) : $decision->decision['reason'] }}</p><p class="text-xs">نسخة الاقتراح {{ $row->latest->context_version }} · {{ $row->latest->provider }}</p>@else لم يصدر اقتراح @endif</td>
                    <td class="p-4">@if($decision)<p>{{ $decision->policy['allowed_proposal'] ? 'اقتراح مقبول للمحاكاة' : 'اقتراح مرفوض' }}</p><p>{{ $decision->policy['reason_code'] }}</p><p>تنفيذ بواسطة الـAgent: لم يحدث</p>@endif @if($row->error_code || $row->latest?->error_code)<p class="text-red-700">{{ $row->error_code ?? $row->latest?->error_code }}</p>@endif</td>
                </tr>
            @empty <tr><td colspan="4" class="p-6">لا توجد طلبات مطابقة.</td></tr> @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $rows->links() }}</div>

@php
    $execution = $activity['execution'];
    $outcomes = $execution['outcomes'];
    $quotaPercent = $cockpit['daily_limit'] > 0 ? min(100, round($cockpit['attempts'] / $cockpit['daily_limit'] * 100, 1)) : 0;
    $attentionStage = in_array($activity['stage'], ['provider_unavailable','worker_unknown','stalled','quota_deferred','observation_disabled','evaluation_disabled','attention_required'], true);
    $finished = $execution['rows']->filter(fn ($row) => $row['receipt']->status === 'admitted' && in_array($row['receipt']->executor_type, ['puzzle','codex_scene'], true) && in_array($row['current_status'], ['succeeded','completed'], true))->unique(fn ($row) => $row['receipt']->executor_type.':'.$row['receipt']->executor_id)->take(3);
    $activeWork = $execution['rows']->filter(fn ($row) => $row['receipt']->status === 'admitted' && in_array($row['current_status'], ['queued','pending','processing','preparing','running','waiting_slot','sending','waiting_identity','waiting_response','waiting_staff','retry_queued','retry_authorized','stopping'], true))->take(4);
@endphp
<div class="pilot-heading">
    <div><p class="pilot-eyebrow" lang="en" dir="ltr">HEROKID / MISSION CONTROL</p><h2>مركز قيادة الطلبات</h2><p class="pilot-muted">كل الطلبات. رؤية واحدة.</p></div>
    <div class="pilot-heading-status"><p class="pilot-caption">Auto Pilot بيعمل إيه دلوقتي؟</p><span class="pilot-status {{ $attentionStage ? 'pilot-status-attention' : '' }}">{{ __('agentic.activity.'.$activity['stage']) }}</span><p>{{ !$execution['enabled'] ? 'Shadow Mode — التنفيذ غير مفعّل' : ($execution['control']?->paused ? 'تسليم خطوات جديدة متوقف يدويًا' : 'التنفيذ مفعّل ضمن الصلاحيات المعتمدة') }}</p><p>{{ $execution['active_count'] ? $execution['active_count'].' مسار جارٍ أو منتظر' : 'لا يوجد تنفيذ جارٍ أو منتظر الآن' }}</p>
        @if($activity['order'])<p><a class="pilot-current-order" href="{{ route('admin.agentic.show', $activity['order']) }}">{{ $activity['working'] ? 'يراجع الآن' : 'آخر مراجعة' }} <bdi>{{ $activity['order_reference'] }}</bdi></a></p>@endif
        <small>قراءة اللوحة: {{ $cockpit['read_at']->timezone('Africa/Cairo')->format('H:i:s') }} · القاهرة</small></div>
</div>
<div class="pilot-kpis" aria-label="مؤشرات Auto Pilot">
    <div class="pilot-kpi"><span>الطلبات النشطة</span><strong>{{ number_format($cockpit['active']) }}</strong><small>أوردر مجمّع · دون تكرار الأجزاء</small></div>
    <div class="pilot-kpi"><span>خطوات إنتاج مكتملة</span><strong>{{ number_format($outcomes['production_completed']) }}</strong><small>منذ التفعيل · ليست أوردرات مُسلّمة</small></div>
    <div class="pilot-kpi"><span>قيد التنفيذ الآن</span><strong class="pilot-number-live">{{ number_format($outcomes['running']) }}</strong><small>{{ $outcomes['waiting'] }} مسار في الطابور أو انتظار رد</small></div>
    <div class="pilot-kpi pilot-quota"><span>تقييمات الذكاء الاصطناعي اليوم</span><strong><bdi>{{ number_format($cockpit['attempts']) }} <em>/ {{ number_format($cockpit['daily_limit']) }}</em></bdi></strong><progress max="100" value="{{ $quotaPercent }}" aria-label="استهلاك حصة تقييمات اليوم">{{ $quotaPercent }}%</progress><small>{{ $quotaPercent }}% مستخدمة · بتوقيت القاهرة</small></div>
</div>
@if($execution['active_count'])
<div class="pilot-live" aria-label="العمل الحالي"><div><b>مسارات العمل الحالية</b>
@foreach($activeWork as $row)<p><a href="{{ route('admin.orders.show', $row['receipt']->order_id) }}"><bdi>{{ $row['order']?->checkoutReference?->short_reference ?: 'Order '.$row['receipt']->order_id }}</bdi></a> · {{ __('agentic.actions.'.$row['receipt']->action) }} · {{ __('agentic.executor_states.'.$row['current_status']) }}</p>@endforeach
</div></div>
@endif
<div class="pilot-heartbeat">آخر إشارة من العامل: {{ $activity['updated_at'] ? \Carbon\Carbon::parse($activity['updated_at'])->timezone('Africa/Cairo')->format('d/m H:i:s') : 'لم تصل بعد' }} · القاهرة</div>
@if(in_array($activity['stage'], ['worker_unknown','stalled'], true))<p class="pilot-warning" role="status">لا توجد إشارة حديثة تثبت استمرار العمل. لا تعتبر آخر حالة معروضة تأكيدًا لاستمرار التنفيذ.</p>@endif
<div class="pilot-grid">
    <section class="pilot-board" aria-label="الإنجاز والانتظار والتدخل">
        <div class="pilot-lane pilot-lane-done"><h3>تم إنجازه <span>{{ $outcomes['production_completed'] }}</span></h3><p class="pilot-caption">أحدث خطوات الإنتاج المكتملة</p>
            @forelse($finished as $row)
                <a class="pilot-item" href="{{ route('admin.orders.show', $row['receipt']->order_id) }}"><strong dir="ltr">{{ $row['order']?->checkoutReference?->short_reference ?: 'Order '.$row['receipt']->order_id }}</strong><span>{{ __('agentic.actions.'.$row['receipt']->action) }}</span><small class="pilot-success">اكتملت الخطوة وسُجلت نتيجتها</small></a>
            @empty <p class="pilot-empty">لا توجد خطوات إنتاج مكتملة ضمن أحدث السجلات.</p>@endforelse
            <a class="pilot-lane-link" href="#pilot-executions">عرض سجل التنفيذ</a>
        </div>
        @foreach(['wait' => ['ينتظر', 'آخر اقتراح لكل أوردر نشط؛ قد يشمل انتظار الفريق'], 'attention' => ['يحتاج تدخلك', 'مراجعة أو قرار أو استكمال بشري']] as $bucket => [$title, $caption])
            <div class="pilot-lane pilot-lane-{{ $bucket }}"><h3>{{ $title }} <span>{{ array_sum(array_column($cockpit['groups'][$bucket], 'count')) }}</span></h3><p class="pilot-caption">{{ $caption }}</p>
                @forelse(array_slice($cockpit['groups'][$bucket], 0, 4) as $group)
                    <a class="pilot-item" href="{{ route('admin.agentic.index', ['decision' => $group['decision'], 'reason' => $group['reason']]) }}#pilot-orders"><strong>{{ __('agentic.dashboard_reasons.'.$group['reason']) !== 'agentic.dashboard_reasons.'.$group['reason'] ? __('agentic.dashboard_reasons.'.$group['reason']) : __('agentic.reasons.'.$group['reason']) }}</strong><span>{{ $group['count'] }} أوردر · عرض الطلبات</span></a>
                @empty <p class="pilot-empty">لا توجد اقتراحات حديثة من هذا النوع. الطلبات غير المقيّمة تظهر في سجل الطلبات.</p>@endforelse
                <a class="pilot-lane-link" href="{{ route('admin.agentic.index', ['decision' => $bucket === 'wait' ? 'wait' : 'human_required']) }}#pilot-orders">{{ $bucket === 'wait' ? 'كل اقتراحات الانتظار' : 'طلبات التدخل البشري' }}</a>
                @if($bucket === 'attention')<a class="pilot-lane-link" href="{{ route('admin.agentic.index', ['decision' => 'escalate']) }}#pilot-orders">طلبات التصعيد</a>@endif
            </div>
        @endforeach
        <p class="pilot-board-note">انتظار وتدخل: آخر اقتراح محفوظ لكل أوردر، وليس حصرًا لكل وحداته. التغييرات غير المرصودة والنتائج القديمة لا تدخل هذا التصنيف. اكتمال الإنتاج لا يعني اعتماد العميل أو تسليم الأوردر.</p>
    </section>
    <aside class="pilot-diagnostics" aria-label="العوائق والتغطية">
        <h3>لماذا لم ينفّذ؟</h3><p class="pilot-rejection-total"><strong>{{ $execution['not_admitted'] }}</strong> محاولة لم تدخل التنفيذ <small>منذ التفعيل؛ قد يتكرر نفس الأوردر</small></p>
        @foreach($cockpit['rejections']->take(3) as $rejection)<div class="pilot-diagnostic-row"><span>{{ match ($rejection->reason_code) { 'EXECUTOR_PREFLIGHT_FAILED' => 'فحص الخدمة لم يسمح', 'EXECUTION_STALE_CONTEXT' => 'تغيّرت بيانات الطلب أو الذاكرة', default => 'سبب آخر؛ راجع سجل التنفيذ' } }}</span><b>{{ $rejection->total }}</b></div>@endforeach
        <h3 class="pilot-subheading">تغطية التقييم</h3>
        @foreach(['covered' => 'مكتملة للنسخة المرصودة', 'uncovered' => 'لم تكتمل أو تغيّرت', 'unobserved' => 'منها لم تُرصد بعد', 'errors' => 'منها أخطاء تحتاج مراجعة'] as $key => $label)<div class="pilot-diagnostic-row"><span>{{ $label }}</span><b>{{ $cockpit[$key] }}</b></div>@endforeach
        <p class="pilot-caption">التغطية تخص التقييم فقط؛ لا تعني اكتمال تنفيذ الأوردر.</p>
        <a class="pilot-button" href="#pilot-executions">مراجعة عوائق التنفيذ</a><a class="pilot-button pilot-button-secondary" href="{{ route('admin.agentic.memory.index') }}">الذاكرة والتصحيحات</a>
    </aside>
</div>
<div class="pilot-telemetry">
    <div><b>{{ $execution['readiness']['targets'] }}</b><span>وحدات جاهزة للفحص تنتظر تقييمًا</span><small>في {{ $execution['readiness']['checkouts'] }} أوردر؛ لا يشمل ما سبق تقييمه أو منعه</small></div>
    <div><b>{{ $outcomes['message_accepted'] }}</b><span>طلبات إرسال قبلها مزوّد الرسائل</span><small>منذ التفعيل؛ لا يثبت وصولها للعميل</small></div>
    <div><b>{{ $outcomes['failed'] + $outcomes['review'] + $outcomes['unknown'] + $outcomes['stopped'] }}</b><span>مسارات تنفيذ تحتاج متابعة</span><small>{{ $outcomes['failed'] }} فشل · {{ $outcomes['review'] }} مراجعة · {{ $outcomes['unknown'] }} غير مؤكدة · {{ $outcomes['stopped'] }} توقفت</small></div>
</div>
<details id="pilot-executions" class="pilot-details"><summary>سجل التنفيذ والعوائق <span>{{ $execution['total'] }} إيصالًا · النتائج والضوابط</span></summary><div class="pilot-details-body">@include('admin.agentic._execution', ['execution' => $execution, 'allowStop' => true])</div></details>

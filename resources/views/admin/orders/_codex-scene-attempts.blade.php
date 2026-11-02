@if($attemptRun->scene_progress)
<details class="mt-2 text-xs">
    <summary class="cursor-pointer font-bold">المحاولات ونتائج المشاهد</summary>
    @foreach($attemptRun->scene_progress as $number => $progress)
        <div class="mt-2 rounded-lg border p-2">
            <strong>مشهد {{ $number }} · {{ count($progress['attempts']) }} محاولة في هذا التشغيل</strong>
            <span> — {{ ['succeeded'=>'محفوظ', 'retained'=>'محفوظ من محاولة سابقة', 'running'=>'جاري العمل', 'failed'=>'يحتاج استكمالًا يدويًا'][$progress['status']] ?? 'قيد المراجعة' }}</span>
            @if(isset($progress['previous_run_id']))<p>تم تجاوز رفض محتوى مسجل في المحاولة السابقة؛ لم نكرر المشهد.</p>@endif
            @foreach($progress['attempts'] as $attempt)
                <p class="mt-1">المحاولة {{ $attempt['number'] }}: {{ ['running'=>'جاري التوليد', 'succeeded'=>'نجحت', 'failed'=>'لم تنجح'][$attempt['status']] ?? '' }}
                    @if(isset($attempt['code'])) — {{ \App\Models\CodexSceneRun::ERRORS[$attempt['code']] ?? 'تحتاج مراجعة' }}@endif
                </p>
            @endforeach
        </div>
    @endforeach
</details>
@if($attemptRun->status === 'needs_manual')
<p class="mt-2 text-sm font-bold text-amber-800">المشاهد المطلوب استكمالها: {{ implode('، ', array_diff(range(1,$attemptRun->scene_count ?? 13), array_keys($attemptRun->scenes ?? []))) }}</p>
@endif
@endif

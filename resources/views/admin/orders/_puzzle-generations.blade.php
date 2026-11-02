@if($puzzleRuns->isNotEmpty() || ($puzzleMonitor ?? false))
<section class="rounded-2xl border bg-white p-5 space-y-3" dir="rtl">
    <h3 class="text-lg font-bold">أوتوميشن صور المنتجات</h3>
    <p class="text-sm text-gray-600">{{ app(\App\Services\ProductAutomation\PuzzleAutomation::class)->enabled() ? 'مفعّل للتأكيدات الجديدة' : 'متوقف' }} · صورة واحدة لكل بند بازل أو مج، محفوظة في المرفقات والمعاينة. لا تُرسل رسالة معاينة للعميل في هذه المرحلة.</p>
    @if($puzzleMonitor ?? false)<p class="text-xs text-gray-500">آخر 40 محاولة؛ التفاصيل وسجل Automation داخل كل طلب.</p>@endif
    @forelse($puzzleRuns as $puzzleRun)
        <div class="rounded-xl border p-3 text-sm space-y-2">
            <a class="font-bold text-indigo-700 underline" href="{{ route('admin.orders.show', $puzzleRun->order_id) }}">{{ $puzzleRun->order?->checkoutReference?->short_reference ?: $puzzleRun->order?->order_number }} · {{ $puzzleRun->item?->title }}</a>
            <p>{{ \App\Models\PuzzleGeneration::LABELS[$puzzleRun->status] ?? $puzzleRun->status }} · بواسطة Automation</p>
            @if(\App\Services\Orders\AutomationHandoff::held($puzzleRun->checkout_key) && $puzzleRun->status === 'queued' && ! $puzzleRun->requested_by)<p>متوقف مؤقتًا لأن الفريق استلم الطلب.</p>@endif
            @if($puzzleRun->error_code)<p class="text-amber-700">تحتاج مراجعة: <span dir="ltr">{{ $puzzleRun->error_code }}</span>. لا تتم إعادة التوليد تلقائيًا.</p>@endif
            @if($puzzleRun->attachment_id)<a class="text-indigo-700 underline" href="{{ route('admin.orders.attachments.show', $puzzleRun->attachment_id) }}" target="_blank" rel="noopener">فتح صورة المنتج</a>@endif
            @if($puzzleRun->status === 'review' && $puzzleRun->output_path)<a class="text-indigo-700 underline" href="{{ route('admin.orders.puzzle-image', [$puzzleRun->order_id, $puzzleRun->id]) }}" target="_blank" rel="noopener">فتح الصورة المحفوظة للمراجعة</a>@endif
            <p class="text-xs text-gray-500">{{ $puzzleRun->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }} · المحاولة {{ $puzzleRun->attempt_number }}{{ $puzzleRun->finished_at ? ' · انتهت '.$puzzleRun->finished_at->timezone('Africa/Cairo')->format('H:i:s') : '' }}</p>
        </div>
    @empty <p class="text-gray-500">لم تبدأ أي محاولة إنتاج صورة منتج بعد.</p>
    @endforelse
</section>
@endif

@if($retryState && in_array($retryState->error_code, \App\Services\Agentic\Providers\TransientDecisionFailure::CODES, true))
    <p class="text-amber-700">تعذر اتصال طلب القرار أو انتهت مهلته.
        @if(in_array($retryState->status, ['pending', 'quota_deferred'], true) && $retryState->next_evaluation_at)
            أقرب موعد لإعادة تقييم القرار: <bdi>{{ \Carbon\Carbon::parse($retryState->next_evaluation_at)->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }}</bdi> بتوقيت القاهرة، حسب جاهزية المساعد والحصة المتاحة. لا تُعاد خطوات الإنتاج أو الرسائل.
        @else
            توقفت المحاولات التلقائية؛ يلزم فحص المسؤول.
        @endif
    </p>
@endif

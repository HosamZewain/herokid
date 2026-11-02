<?php
// Invoked only by the reviewed stdin diagnostic wrapper. No remote files written.
// $builder is the current read-only context builder with compatibility specs only.
use Illuminate\Support\Facades\DB;
use App\Models\{Order, CodexSceneRun};

DB::statement('SET SESSION MAX_EXECUTION_TIME=10000');
DB::statement('SET TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $active = app(\App\Services\Orders\AdminOrderGroupService::class)->activeOrdersQuery();
    $candidates = collect();
    $candidates = $candidates->merge(DB::table('order_automation_holds')->orderByDesc('created_at')->limit(5)->pluck('checkout_key'));
    foreach (['uncertain', 'failed', 'running', 'completed', 'waiting_slot'] as $status) {
        $candidates = $candidates->merge(CodexSceneRun::where('status', $status)->latest('created_at')->limit(3)->pluck('checkout_key'));
    }
    $candidates = $candidates->merge(DB::table('order_payment_reviews')->where('status', 'pending')->latest('id')->limit(4)->pluck('checkout_key'));
    foreach (['new', 'new_confirmed', 'costomer_confirmed', 'quality_review', 'generating', 'ready_preview', 'printing', 'cancelled', 'delivered'] as $status) {
        $candidates = $candidates->merge(Order::where('status', $status)->latest('id')->limit(4)->pluck('checkout_group_key'));
    }
    $candidates = $candidates->merge((clone $active)->orderBy('id')->limit(4)->pluck('checkout_group_key'));
    $cases = []; $errors = 0; $seen = [];
    foreach ($candidates->filter()->unique()->take(48) as $key) {
        try {
            $canonical = $builder->canonicalKey($key);
            if (isset($seen[$canonical])) continue;
            $seen[$canonical] = true;
            $context = $builder->build($canonical);
            $caseId = sprintf('real-%02d', count($cases) + 1);
            $legacy = [];
            foreach ($context['units'] as $unit) {
                if ($unit['capability'] === 'story_scenes') {
                    $order = Order::find((int) substr($unit['order_ref'], 6));
                    $legacy[$unit['unit_key']] = ['source' => 'ManualSceneService::reason + automaticEligible + scene-text gate; capacity/media dispatch excluded',
                        'would_auto_admit' => app(\App\Services\CodexScenes\ManualSceneService::class)->automaticEligible($order)
                            && app(\App\Services\CodexScenes\ManualSceneService::class)->reason($order) === null && $unit['prompt_complete']];
                } else {
                    $legacy[$unit['unit_key']] = ['source' => 'documented_existing_service_gates', 'would_auto_admit' => null];
                }
            }
            // Irreversible per-case opaque remapping, before any output leaves AWS.
            unset($context['representative_id'], $context['observed_at'], $context['operational'], $context['fingerprint']);
            $maps = []; $counter = 0;
            $anonymize = function ($value, $field = '') use (&$anonymize, &$maps, &$counter, $caseId) {
                if (in_array($field, ['input_fingerprint', 'spec_fingerprint'], true)) return hash('sha256', $caseId.':'.(++$counter));
                if ($field === 'ledger_ref') return $value === null ? null : 1;
                if (is_array($value)) {
                    $result = [];
                    foreach ($value as $key => $child) $result[$key] = $anonymize($child, (string) $key);
                    return $result;
                }
                if (is_string($value) && preg_match('/\A(?:checkout|order|child-for-order|staff|product|variant|story|item|identity|scenes|image|process|response|manifest|component):/', $value)) {
                    return $maps[$value] ??= explode(':', $value)[0].':'.(++$counter);
                }
                return $value;
            };
            $context = $anonymize($context);
            $context['context_version'] = 1;
            $context['fingerprint'] = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
            $legacy = collect($legacy)->map(fn ($expectation, $target) => ['target_id' => $maps[$target], ...$expectation])->values()->all();
            $cases[] = ['id' => $caseId, 'origin' => 'actual_anonymized_checkout', 'context' => $context, 'existing_automation' => $legacy];
        } catch (\Throwable) { $errors++; }
    }
    echo json_encode(['schema_version' => 1, 'captured_date' => gmdate('Y-m-d'), 'approval' => 'pending_owner_review',
        'active_checkout_count' => (clone $active)->distinct()->count('checkout_group_key'),
        'selection' => 'bounded stratification by run status, manual hold, payment review, lifecycle, plus oldest active; max 48 groups',
        'read_errors' => $errors, 'cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} finally { DB::rollBack(); }

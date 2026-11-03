<?php

namespace App\Services\Agentic;

use App\Models\AiCheckoutState;
use Illuminate\Support\Facades\DB;

class ContextObserver
{
    public function __construct(private OrderContextBuilder $builder, private CapabilityRegistry $registry) {}

    public function markDirty(string $key): void
    {
        AiCheckoutState::insertOrIgnore(['checkout_key' => $key, 'status' => 'unobserved', 'dirty_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        AiCheckoutState::where('checkout_key', $key)->update(['dirty_at' => now(), 'updated_at' => now()]);
    }

    public function observe(string $key): AiCheckoutState
    {
        $canonical = $this->builder->canonicalKey($key);
        if ($canonical !== $key) {
            AiCheckoutState::where('checkout_key', $key)->update(['status' => 'redirected', 'dirty_at' => null]);
            $key = $canonical;
        }
        $context = $this->builder->build($key);
        $targets = $this->registry->targets($context);
        AiCheckoutState::insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($key, $context, $targets) {
            $state = AiCheckoutState::where('checkout_key', $key)->lockForUpdate()->firstOrFail();
            $changed = $state->fingerprint !== $context['fingerprint'];
            if ($changed) {
                $state->context_version++;
                $state->fingerprint = $context['fingerprint'];
                $state->covered_targets = [];
                $state->status = $context['all_complete'] ? 'inactive' : 'pending';
                $state->error_code = null;
                $state->next_evaluation_at = now()->addSeconds(config('agentic.debounce_seconds', 30));
            }
            $context['context_version'] = $state->context_version;
            $state->snapshot = $context;
            $state->representative_id = $context['representative_id'];
            $state->target_count = count($targets);
            $state->blocked = collect($targets)->contains(fn ($t) => in_array($t['decision'], ['human_required', 'escalate'], true));
            $state->observed_at = now();
            $state->dirty_at = null;
            $state->save();

            return $state;
        }, 3);
    }

    public function observeDirty(): int
    {
        $keys = AiCheckoutState::whereNotNull('dirty_at')->where('dirty_at', '<=', now()->subSeconds(config('agentic.debounce_seconds')))
            ->orderBy('dirty_at')->limit(config('agentic.reconcile_batch'))->pluck('checkout_key');
        foreach ($keys as $key) {
            $this->safelyObserve($key);
        }

        return $keys->count();
    }

    public function reconcile(): int
    {
        $control = DB::table('ai_runtime_states')->where('id', 1)->first();
        $query = AutoPilotOrderScope::activeOrdersQuery()
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'))
            ->selectRaw('checkout_group_key, MIN(id) as cursor_id')->groupBy('checkout_group_key')
            ->havingRaw('MIN(id) > ?', [$control->scan_cursor])->orderBy('cursor_id')->limit(config('agentic.reconcile_batch'));
        $groups = $query->get();
        foreach ($groups as $group) {
            $this->safelyObserve($group->checkout_group_key);
        }
        // Query-builder/SQL writes bypass model events. Sweep known states that left
        // the active set too, otherwise a fulfilled checkout can remain pending forever.
        $closed = AiCheckoutState::whereNotIn('status', ['inactive', 'redirected'])
            ->whereNotIn('checkout_key', AutoPilotOrderScope::activeOrdersQuery()->select('checkout_group_key'))
            ->orderBy('observed_at')->limit(config('agentic.reconcile_batch'))->pluck('checkout_key');
        foreach ($closed as $key) {
            $this->safelyObserve($key);
        }
        DB::table('ai_runtime_states')->where('id', 1)->update(['scan_cursor' => $groups->isEmpty() ? 0 : $groups->last()->cursor_id,
            'reconciled_at' => now(), 'updated_at' => now()]);

        return $groups->count();
    }

    private function safelyObserve(string $key): void
    {
        try {
            $this->observe($key);
        } catch (\Throwable) {
            // Do not leak exception SQL, prompts, ciphertext or customer data into logs.
            $this->markDirty($key);
            AiCheckoutState::where('checkout_key', $key)->update(['status' => 'observation_error', 'error_code' => 'CONTEXT_READ_FAILED']);
        }
    }
}

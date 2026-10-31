<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRun;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\User;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\AiProviderAvailability;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Services\OrderAutomation\WorkflowInputs;
use Throwable;

/** Read-only candidate inspection. This is never an execution or activation permit. */
class ProcessIdentityPreflight
{
    public function inspect(int $orderId, int $automationId, int $actorId): array
    {
        $report = ['order_id' => $orderId, 'automation_id' => $automationId, 'actor_id' => $actorId,
            'read_only' => true, 'candidate_valid' => false, 'execution_authorized' => false,
            'external_delivery_and_workers_verified' => false, 'blockers' => [],
            'gates' => ['process_enabled' => (bool) config('order_process.enabled'),
                'identity_enabled' => (bool) config('order_process.identity_enabled'),
                'template_ready' => (bool) config('order_process.identity_template_ready'),
                'trial_scope_pinned' => config('order_process.enrollment_mode') === 'trial'
                    && (int) config('order_process.trial_order_id') === $orderId
                    && (int) config('order_process.trial_automation_id') === $automationId]];
        $order = Order::withTrashed()->find($orderId);
        $automation = OrderAutomation::with('revision')->find($automationId);
        $actor = User::find($actorId);
        if (! $order || ! $automation?->revision || ! $actor) {
            $report['blockers'][] = 'candidate_not_found';

            return $report;
        }
        $definition = $automation->revision->definition;
        $report['revision_id'] = $automation->revision->id;
        $report['stored_revision_checksum'] = $automation->revision->checksum;
        // MySQL JSON reorders object keys. Pin current content canonically rather
        // than comparing its encoding with the historical insertion-order checksum.
        $report['definition_fingerprint'] = hash('sha256', json_encode($this->canonical($definition), JSON_THROW_ON_ERROR));
        try {
            app(ProcessDefinition::class)->validate($definition);
        } catch (Throwable) {
            $report['blockers'][] = 'definition_invalid';

            return $report;
        }
        if (($definition['scope'] ?? '') !== 'checkout' || ($definition['trigger'] ?? '') !== 'status_entered'
            || ($definition['conditions']['type'] ?? '') !== 'story' || ($definition['conditions']['status'] ?? '') !== 'new_confirmed') {
            $report['blockers'][] = 'identity_definition_mismatch';
        }
        $generation = collect($definition['nodes'])->where('type', 'generate_identity');
        $messages = collect($definition['nodes'])->where('type', 'whatsapp');
        if ($generation->count() !== 1 || $messages->count() !== 1
            || data_get($messages->first(), 'config.template') !== ProcessTemplates::IDENTITY
            || data_get($messages->first(), 'config.variables') !== ProcessTemplates::IDENTITY_FIELDS) {
            $report['blockers'][] = 'trial_requires_one_identity_and_one_preview';
        }
        $authority = clone $automation;
        $authority->process_activated_by = $actorId;
        if (! app(ProcessLivePolicy::class)->authorized($authority)) {
            $report['blockers'][] = 'actor_permissions_missing';
        }
        $orders = Order::withTrashed()->where('checkout_group_key', $order->checkoutGroupKey())->get();
        $report['checkout_order_count'] = $orders->count();
        if ($orders->count() !== 1 || $order->trashed() || ! $order->story_id || $order->status !== 'new_confirmed') {
            $report['blockers'][] = 'trial_requires_one_confirmed_story_order';
        }
        if ($definition['conditions']['story_ids'] && ! in_array($order->story_id, $definition['conditions']['story_ids'], true)) {
            $report['blockers'][] = 'story_outside_workflow';
        }
        if (OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->exists()
            || OrderProcessIdentity::where('order_id', $orderId)->exists()
            || OrderAutomationRun::where('live_key', 'story-identity:'.$orderId)->exists()) {
            $report['blockers'][] = 'existing_process_or_identity_claim';
        }
        $identity = $order->childIdentityRequest()->withTrashed()->first();
        if ($identity && ($identity->trashed() || $identity->approved_attempt_id || $identity->orders()->whereKeyNot($orderId)->exists()
            || $identity->attempts()->where(fn ($q) => $q->whereIn('status', ['pending', 'processing'])->orWhereNotNull('output_storage_path'))->exists())) {
            $report['blockers'][] = 'existing_identity_requires_review';
        }
        if (! $report['blockers']) {
            try {
                $context = app(ProcessOrderContext::class)->snapshot($orders->load('items'));
                $inputs = app(WorkflowInputs::class)->capture($order, ['story_ids' => $definition['conditions']['story_ids'], 'photo_limit' => 3, 'prompt' => '{{identity_prompt}}']);
                $report['verified_photo_count'] = count($inputs['photos']);
                $report['input_fingerprint'] = hash('sha256', $context['fingerprint'].$inputs['fingerprint']);
            } catch (Throwable) {
                // Never include exception messages: storage/prompt failures can contain private paths.
                $report['blockers'][] = 'identity_inputs_unavailable';
            }
        }
        $settings = app(ChildIdentitySettings::class);
        [$provider, $model] = $settings->providerAndModel();
        if (! $settings->enabled() || ! $provider || ! $model || ! app(AiProviderAvailability::class)->modelAvailable($model, 'character_sheet')) {
            $report['blockers'][] = 'identity_provider_unavailable';
        } else {
            $estimate = app(AiImagePricingService::class)->calculate($model, $settings->size(), $settings->quality(), []);
            $report['model'] = $model->code;
            $report['estimated_usd_per_identity'] = $estimate['cost_usd'];
            $report['estimate_source'] = 'configured_model_pricing_not_vendor_quote_or_hard_cap';
            $report['workflow_budget_usd'] = (float) data_get($generation->first(), 'config.budget_usd', 0);
            $report['configured_budget_usd'] = (float) config('order_process.identity_budget_usd', 0);
            if (strtoupper((string) ($model->estimated_cost_currency ?: 'USD')) !== 'USD' || ! $estimate['cost_usd']
                || (float) $estimate['cost_usd'] <= 0 || (float) $estimate['cost_usd'] > $report['workflow_budget_usd']) {
                $report['blockers'][] = 'identity_estimate_or_workflow_budget_invalid';
            }
        }
        $report['candidate_valid'] = ! $report['blockers'];
        $report['activation_still_requires'] = ['owner_trial_approval', 'exact_recipient_review', 'worker_and_timeout_checks',
            'pinned_trial_config_and_budget', 'reviewed_future_event_or_explicit_trial_enrollment'];

        return $report;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}

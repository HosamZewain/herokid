<?php

namespace App\Services\Agentic;

/** Capabilities describe safe proposals; they hold no executable callbacks. */
class CapabilityRegistry
{
    public const VERSION = 'autopilot-shadow-policy-v4';

    public const ACTIONS = [
        'send_order_confirmation', 'generate_identity', 'generate_product_image',
        'start_story_scene_generation', 'generate_book_cover', 'generate_book_pages',
        'send_product_preview', 'request_customer_approval', 'attach_asset', 'create_human_task',
    ];

    public function targets(array $context): array
    {
        // Owner clarification: actual customer delivery/cancellation precedes old holds.
        if (AutoPilotOrderScope::complete($context)) {
            return [$this->target($context['checkout_ref'], 'checkout', 'completed', null, 'LIFECYCLE_COMPLETE')];
        }
        $control = $context['automation_control']['state'] ?? 'running';
        if ($control !== 'running') {
            return [$this->target($context['checkout_ref'], 'checkout', in_array($control, ['paused', 'pause_requested'], true) ? 'human_required' : 'escalate', null,
                match ($control) {
                    'pause_requested' => 'AUTOMATION_PAUSE_REQUESTED',
                    'paused' => 'AUTOMATION_PAUSED',
                    default => 'AUTOMATION_CONTROL_UNCERTAIN',
                })];
        }
        if ($context['hold']) {
            return [$this->target($context['checkout_ref'], 'checkout', 'human_required', null, 'MANUAL_HOLD')];
        }
        if ($context['memory']['overflow'] ?? false) {
            return [$this->target($context['checkout_ref'], 'checkout', 'human_required', null, 'MEMORY_CONTEXT_LIMIT')];
        }
        $targets = [];
        if (! $context['payment']['consistent']) {
            $targets[] = $this->target($context['checkout_ref'], 'checkout', 'escalate', null, 'PAYMENT_STATE_CONFLICT');
        } elseif ($context['change_requested']) {
            $targets[] = $this->target($context['checkout_ref'], 'checkout', 'human_required', null, 'CUSTOMER_REQUESTED_CHANGES');
        } elseif ($context['payment']['review_pending']) {
            $targets[] = $this->target($context['checkout_ref'], 'checkout', 'human_required', null, 'PAYMENT_REVIEW_REQUIRED');
        } elseif ($context['confirmation']['required']) {
            $status = $context['confirmation']['status'];
            $targets[] = $this->target($context['checkout_ref'], 'checkout',
                $status === 'unknown' ? 'escalate' : ($context['confirmation']['sendable'] && $status === 'pending' ? 'execute' : (in_array($status, ['pending', 'processing', 'succeeded'], true) ? 'wait' : 'human_required')),
                $context['confirmation']['sendable'] && $status === 'pending' ? 'send_order_confirmation' : null,
                $status === 'unknown' ? 'CONFIRMATION_UNCERTAIN' : 'CONFIRMATION_REQUIRED');
        }
        foreach ($context['units'] as $unit) {
            $targets[] = $this->unitTarget($context, $unit);
        }

        return $targets ?: [$this->target($context['checkout_ref'], 'checkout', 'human_required', null, 'NO_PRODUCTION_UNITS')];
    }

    public function humanTasks(array $context): array
    {
        return array_values(array_map(fn ($target) => [
            'target_id' => $target['target_id'], 'reason_code' => $target['reason_code'],
            'assignee_ref' => $context['assignee_ref'], 'proposed' => true, 'source' => 'read_only_projection',
            'task_type' => match ($target['reason_code']) {
                'PAYMENT_REVIEW_REQUIRED', 'PAYMENT_STATE_CONFLICT' => 'payment_verification',
                'MISSING_REFERENCE_IMAGES', 'PRODUCTION_BRIEF_INCOMPLETE' => 'missing_information',
                'CUSTOMER_REQUESTED_CHANGES', 'IDENTITY_ATTEMPT_REQUIRES_REVIEW', 'IDENTITY_WORKFLOW_REQUIRED' => 'identity_or_customer_correction',
                'OUTPUT_REVIEW_AND_STUDIO' => 'quality_review_and_studio',
                default => 'manual_production_exception',
            },
            'later_human_steps' => ['printing', 'packaging', 'shipping'],
        ], array_filter($this->targets($context), fn ($target) => in_array($target['decision'], ['human_required', 'escalate'], true))));
    }

    private function unitTarget(array $context, array $unit): array
    {
        $result = fn ($decision, $action, $reason) => $this->target($unit['unit_key'], 'production_unit', $decision, $action, $reason);
        $order = collect($context['orders'])->firstWhere('order_ref', $unit['order_ref']) ?? ['status' => $unit['order_status']];
        if (! $unit['active'] || AutoPilotOrderScope::terminal($order)) {
            return $result('completed', null, 'UNIT_INACTIVE');
        }
        if ($context['change_requested']) {
            return $result('human_required', null, 'CUSTOMER_REQUESTED_CHANGES');
        }
        if (! $context['payment']['consistent']) {
            return $result('escalate', null, 'PAYMENT_STATE_CONFLICT');
        }
        $production = $unit['production'];
        if ($production['uncertain'] || $unit['identity_status'] === 'uncertain') {
            return $result('escalate', null, 'EXTERNAL_RESULT_UNCERTAIN');
        }
        if ($production['active'] || in_array($unit['identity_status'], ['queued', 'pending', 'processing'], true) || $unit['legacy_conflict']) {
            return $result('wait', null, 'EXISTING_WORK_IN_PROGRESS');
        }
        if (($order['order_behavior'] ?? $unit['order_status']) === 'delivered') {
            if (($order['shipping_behavior'] ?? $order['shipping_status'] ?? null) === 'shipped') {
                return $result('wait', null, 'CUSTOMER_DELIVERY_PENDING');
            }
            if ($unit['capability'] === 'book_interior' && $production['attempted'] && $production['missing_numbers']) {
                return $result('human_required', null, 'PRESERVE_OUTPUT_REVIEW_ATTEMPT');
            }
            // Keep fulfilment visible, without regenerating approved products or
            // enrolling historical books. Only the existing SELECT-only admission
            // rule can let an unstarted interior proceed to the other preflights.
            if ($unit['capability'] !== 'book_interior' || $production['attempted'] || ! ($unit['book_admission_eligible'] ?? false)
                || ($order['printing_status'] ?? null) !== 'not_started' || ($order['shipping_status'] ?? null) !== 'not_ready') {
                return $result('human_required', null, 'FULFILLMENT_REQUIRES_TEAM');
            }
        }
        if ($production['attempted'] || $unit['output_count'] > 0) {
            return $result('human_required', null, in_array($production['status'], ['completed', 'succeeded'], true) ? 'OUTPUT_REVIEW_AND_STUDIO' : 'PRESERVE_OUTPUT_REVIEW_ATTEMPT');
        }
        if (! $unit['supported']) {
            return $result('human_required', null, 'UNSUPPORTED_CAPABILITY');
        }
        if (! $unit['variant_valid'] || ! ($unit['linked_story_valid'] ?? true)) {
            return $result('human_required', null, 'VARIANT_OR_IDENTITY_LINK_REQUIRES_REVIEW');
        }
        if ($unit['order_status'] === 'new') {
            return $result('wait', null, 'CONFIRMATION_REQUIRED');
        }
        if (! $unit['has_photos'] && ! ($unit['capability'] === 'story_scenes' && $unit['identity_approved'])) {
            return $result('human_required', null, 'MISSING_REFERENCE_IMAGES');
        }
        if ($unit['capability'] === 'story_scenes' && ! $unit['identity_approved']) {
            if ($unit['identity_status'] === 'succeeded') {
                return $result('wait', null, 'CUSTOMER_IDENTITY_APPROVAL_REQUIRED');
            }

            // Existing workflow owns identity creation; do not enroll historical orders.
            return $result($context['automation_owned'] ? 'wait' : 'human_required', null,
                $unit['identity_status'] ? 'IDENTITY_ATTEMPT_REQUIRES_REVIEW' : 'IDENTITY_WORKFLOW_REQUIRED');
        }
        if ($unit['capability'] === 'book_interior' && ! ($unit['cover_approved'] ?? false)) {
            return $result('wait', null, 'CUSTOMER_COVER_APPROVAL_REQUIRED');
        }
        if (in_array($unit['capability'], ['story_scenes', 'book_interior'], true) && ! $context['payment']['positive_recorded']) {
            return $result('wait', null, 'RECORDED_PAYMENT_REQUIRED');
        }
        if (! $unit['prompt_complete']) {
            return $result('human_required', null, 'PRODUCTION_BRIEF_INCOMPLETE');
        }
        if ($unit['capability'] === 'book_interior' && ! $unit['linked_story_complete']) {
            return $result('wait', null, 'LINKED_STORY_REQUIRED');
        }
        if ($context['automation_owned']) {
            return $result('wait', null, 'EXISTING_AUTOMATION_OWNER');
        }
        if (in_array($unit['capability'], ['story_scenes', 'book_interior'], true) && $context['global_uncertain']) {
            return $result('escalate', null, 'CODEX_POOL_UNCERTAIN');
        }
        $enabled = match ($unit['capability']) {
            'story_scenes' => $context['runtime']['scenes_enabled'] && $context['runtime']['scenes_automatic']
                && in_array($unit['order_status'], ['new_confirmed', 'costomer_confirmed'], true),
            'book_interior' => $context['runtime']['reviews_enabled'] && $context['runtime']['book_pages_enabled']
                && (in_array($unit['order_status'], ['new_confirmed', 'costomer_confirmed', 'ready_preview', 'pending_team_review'], true)
                    || ($unit['order_status'] === 'delivered' && ($unit['book_admission_eligible'] ?? false))),
            'book_cover' => $context['runtime']['reviews_enabled'] && $context['runtime']['products_enabled'],
            'single_image' => $context['runtime']['products_enabled'],
            default => false,
        };
        if (! $enabled) {
            return $result('human_required', null, 'EXECUTOR_OR_STAGE_UNAVAILABLE');
        }

        return $result('execute', match ($unit['capability']) {
            'story_scenes' => 'start_story_scene_generation', 'book_interior' => 'generate_book_pages',
            'book_cover' => 'generate_book_cover', default => 'generate_product_image',
        }, 'READY_FOR_PRODUCTION_PREFLIGHT');
    }

    private function target(string $id, string $type, string $decision, ?string $action, string $reason): array
    {
        return ['target_id' => $id, 'target_type' => $type, 'decision' => $decision, 'action' => $action,
            'reason_code' => $reason, 'requires_human' => in_array($decision, ['human_required', 'escalate'], true),
            // Proposal eligibility is not a claim that byte-level provider/media preflight passed.
            'requires_executor_preflight' => $decision === 'execute'];
    }
}

<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationRun;
use App\Models\ProductionAutomationStep;
use App\Models\User;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductionAutomationStateMachine
{
    private const RUN_TRANSITIONS = [
        ProductionAutomation::STATUS_QUEUED => [
            ProductionAutomation::STATUS_RUNNING,
            ProductionAutomation::STATUS_PAUSED_REVIEW,
            ProductionAutomation::STATUS_PAUSED_BUDGET,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_RUNNING => [
            ProductionAutomation::STATUS_PAUSED_RECOVERABLE,
            ProductionAutomation::STATUS_PAUSED_BUDGET,
            ProductionAutomation::STATUS_PAUSED_REVIEW,
            ProductionAutomation::STATUS_PROVIDER_FAILED,
            ProductionAutomation::STATUS_FILES_READY,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_PAUSED_RECOVERABLE => [
            ProductionAutomation::STATUS_RUNNING,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_PAUSED_BUDGET => [
            ProductionAutomation::STATUS_RUNNING,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_PAUSED_REVIEW => [
            ProductionAutomation::STATUS_RUNNING,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_PROVIDER_FAILED => [
            ProductionAutomation::STATUS_RUNNING,
            ProductionAutomation::STATUS_PAUSED_REVIEW,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_FILES_READY => [
            ProductionAutomation::STATUS_PAUSED_REVIEW,
            ProductionAutomation::STATUS_COMPLETED,
            ProductionAutomation::STATUS_CANCELLED,
            ProductionAutomation::STATUS_FAILED,
        ],
        ProductionAutomation::STATUS_CANCELLED => [],
        ProductionAutomation::STATUS_FAILED => [],
        ProductionAutomation::STATUS_COMPLETED => [
            ProductionAutomation::STATUS_PAUSED_REVIEW,
        ],
    ];

    private const STEP_TRANSITIONS = [
        ProductionAutomation::STEP_PENDING => [
            ProductionAutomation::STEP_QUEUED,
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_COMPLETED,
            ProductionAutomation::STEP_SKIPPED,
            ProductionAutomation::STEP_CANCELLED,
            ProductionAutomation::STEP_FAILED,
        ],
        ProductionAutomation::STEP_QUEUED => [
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_COMPLETED,
            ProductionAutomation::STEP_FAILED_RECOVERABLE,
            ProductionAutomation::STEP_PROVIDER_FAILED,
            ProductionAutomation::STEP_FAILED,
            ProductionAutomation::STEP_CANCELLED,
        ],
        ProductionAutomation::STEP_RUNNING => [
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_COMPLETED,
            ProductionAutomation::STEP_FAILED_RECOVERABLE,
            ProductionAutomation::STEP_PROVIDER_FAILED,
            ProductionAutomation::STEP_FAILED,
            ProductionAutomation::STEP_CANCELLED,
        ],
        ProductionAutomation::STEP_WAITING_REVIEW => [
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_COMPLETED,
            ProductionAutomation::STEP_SKIPPED,
            ProductionAutomation::STEP_FAILED,
            ProductionAutomation::STEP_CANCELLED,
        ],
        ProductionAutomation::STEP_FAILED_RECOVERABLE => [
            ProductionAutomation::STEP_QUEUED,
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_FAILED,
            ProductionAutomation::STEP_CANCELLED,
        ],
        ProductionAutomation::STEP_PROVIDER_FAILED => [
            ProductionAutomation::STEP_QUEUED,
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_FAILED,
            ProductionAutomation::STEP_CANCELLED,
        ],
        ProductionAutomation::STEP_COMPLETED => [],
        ProductionAutomation::STEP_SKIPPED => [],
        ProductionAutomation::STEP_FAILED => [],
        ProductionAutomation::STEP_CANCELLED => [],
    ];

    public function transitionRun(ProductionAutomationRun|int $run, string $toStatus, array $context = [], ?User $actor = null, string $process = 'system'): ProductionAutomationRun
    {
        $runId = $run instanceof ProductionAutomationRun ? $run->id : $run;

        return DB::transaction(function () use ($runId, $toStatus, $context, $actor, $process): ProductionAutomationRun {
            $locked = ProductionAutomationRun::query()
                ->with('project')
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();
            $fromStatus = $locked->status;

            $this->assertKnownRunStatus($toStatus);

            if ($fromStatus === $toStatus) {
                ProductionStudio::log($locked->project, 'automation.transition.duplicate', 'تم تجاهل انتقال مكرر لدورة الإنتاج التلقائي.', [
                    'run_id' => $locked->id,
                    'status' => $toStatus,
                    'process' => $process,
                    'context' => $this->safeContext($context),
                ], $actor);

                return $locked;
            }

            if (! in_array($toStatus, self::RUN_TRANSITIONS[$fromStatus] ?? [], true)) {
                throw new RuntimeException("Invalid automation transition from {$fromStatus} to {$toStatus}.");
            }

            $now = now();
            $updates = [
                'status' => $toStatus,
                'version' => $locked->version + 1,
                'last_transition_at' => $now,
                'last_heartbeat_at' => $now,
                'safe_failure_code' => $context['safe_failure_code'] ?? null,
                'safe_failure_summary' => $context['safe_failure_summary'] ?? null,
            ];

            if ($locked->last_transition_at) {
                $elapsed = max(0, $locked->last_transition_at->diffInSeconds($now));

                if ($fromStatus === ProductionAutomation::STATUS_RUNNING) {
                    $updates['active_seconds'] = (int) $locked->active_seconds + $elapsed;
                } elseif (in_array($fromStatus, ProductionAutomation::pausedStatuses(), true)) {
                    $updates['paused_seconds'] = (int) $locked->paused_seconds + $elapsed;
                }
            }

            if ($toStatus === ProductionAutomation::STATUS_RUNNING && ! $locked->started_at) {
                $updates['started_at'] = $now;
            }

            if (in_array($toStatus, ProductionAutomation::pausedStatuses(), true)) {
                $updates['paused_at'] = $now;
                $updates['pause_reason'] = $context['pause_reason'] ?? $toStatus;
            }

            if ($toStatus === ProductionAutomation::STATUS_FILES_READY) {
                $updates['files_ready_at'] = $now;
                $updates['current_stage'] = 'quality_check';
            }

            if ($toStatus === ProductionAutomation::STATUS_CANCELLED) {
                $updates['cancelled_at'] = $now;
                $updates['cancelled_by_user_id'] = $actor?->id;
            }

            if ($toStatus === ProductionAutomation::STATUS_COMPLETED) {
                $updates['completed_at'] = $now;
                $updates['completed_by_user_id'] = $actor?->id;
            }

            if (in_array($toStatus, ProductionAutomation::terminalStatuses(), true)) {
                $updates['active_project_id'] = null;
            }

            if (array_key_exists('current_stage', $context)) {
                $updates['current_stage'] = $context['current_stage'];
            }

            if (array_key_exists('current_step_key', $context)) {
                $updates['current_step_key'] = $context['current_step_key'];
            }

            if (array_key_exists('blockers', $context)) {
                $updates['blockers_json'] = $context['blockers'];
            }

            if (array_key_exists('active_project_id', $context)) {
                $updates['active_project_id'] = $context['active_project_id'];
            }

            $locked->update($updates);

            ProductionStudio::log($locked->project, 'automation.transition', 'تم تحديث حالة دورة الإنتاج التلقائي.', [
                'run_id' => $locked->id,
                'from' => $fromStatus,
                'to' => $toStatus,
                'process' => $process,
                'context' => $this->safeContext($context),
            ], $actor);

            return $locked->fresh(['project']);
        });
    }

    public function transitionStep(ProductionAutomationStep|int $step, string $toStatus, array $context = [], ?User $actor = null, string $process = 'system'): ProductionAutomationStep
    {
        $stepId = $step instanceof ProductionAutomationStep ? $step->id : $step;

        return DB::transaction(function () use ($stepId, $toStatus, $context, $actor, $process): ProductionAutomationStep {
            $locked = ProductionAutomationStep::query()
                ->with(['run.project'])
                ->whereKey($stepId)
                ->lockForUpdate()
                ->firstOrFail();
            $fromStatus = $locked->status;

            $this->assertKnownStepStatus($toStatus);

            if ($fromStatus === $toStatus) {
                ProductionStudio::log($locked->run->project, 'automation.step_transition.duplicate', 'تم تجاهل انتقال مكرر لخطوة الإنتاج التلقائي.', [
                    'run_id' => $locked->automation_run_id,
                    'step_id' => $locked->id,
                    'step_key' => $locked->step_key,
                    'status' => $toStatus,
                    'process' => $process,
                    'context' => $this->safeContext($context),
                ], $actor);

                return $locked;
            }

            if (! in_array($toStatus, self::STEP_TRANSITIONS[$fromStatus] ?? [], true)
                && ! $this->isManualRetryOverride($fromStatus, $toStatus, $context)
                && ! $this->isManualInvalidationOverride($fromStatus, $toStatus, $context)) {
                throw new RuntimeException("Invalid automation step transition from {$fromStatus} to {$toStatus}.");
            }

            $now = now();
            $updates = [
                'status' => $toStatus,
                'run_version' => $locked->run->version,
                'heartbeat_at' => $now,
                'safe_failure_code' => $context['safe_failure_code'] ?? null,
                'safe_failure_summary' => $context['safe_failure_summary'] ?? null,
            ];

            if ($toStatus === ProductionAutomation::STEP_QUEUED) {
                $updates['queued_at'] = $locked->queued_at ?: $now;
                $updates['failed_at'] = null;
            }

            if ($toStatus === ProductionAutomation::STEP_RUNNING) {
                $updates['started_at'] = $locked->started_at ?: $now;
                $updates['started_by_user_id'] = $actor?->id ?? $locked->started_by_user_id;
            }

            if (in_array($toStatus, [ProductionAutomation::STEP_COMPLETED, ProductionAutomation::STEP_SKIPPED], true)) {
                $updates['completed_at'] = $now;
                $updates['approved_by_user_id'] = $actor?->id ?? $locked->approved_by_user_id;
                $updates['approval_type'] = $context['approval_type'] ?? $locked->approval_type;
            }

            if (in_array($toStatus, [
                ProductionAutomation::STEP_FAILED,
                ProductionAutomation::STEP_FAILED_RECOVERABLE,
                ProductionAutomation::STEP_PROVIDER_FAILED,
            ], true)) {
                $updates['failed_at'] = $now;
            }

            foreach ([
                'input_fingerprint',
                'output_fingerprint',
                'provider_request_id',
                'validation_policy_version',
                'metadata_json',
                'validation_summary_json',
                'attempt_number',
            ] as $field) {
                if (array_key_exists($field, $context)) {
                    $updates[$field] = $context[$field];
                }
            }

            $locked->update($updates);

            if (! in_array($toStatus, [
                ProductionAutomation::STEP_COMPLETED,
                ProductionAutomation::STEP_SKIPPED,
                ProductionAutomation::STEP_CANCELLED,
                ProductionAutomation::STEP_FAILED,
            ], true)) {
                $locked->run()->update([
                    'current_stage' => $locked->stage,
                    'current_step_key' => $locked->step_key,
                    'last_heartbeat_at' => $now,
                ]);
            }

            ProductionStudio::log($locked->run->project, 'automation.step_transition', 'تم تحديث حالة خطوة الإنتاج التلقائي.', [
                'run_id' => $locked->automation_run_id,
                'step_id' => $locked->id,
                'step_key' => $locked->step_key,
                'from' => $fromStatus,
                'to' => $toStatus,
                'process' => $process,
                'context' => $this->safeContext($context),
            ], $actor);

            return $locked->fresh(['run.project']);
        });
    }

    private function assertKnownRunStatus(string $status): void
    {
        if (! array_key_exists($status, self::RUN_TRANSITIONS)) {
            throw new RuntimeException("Unknown automation run status {$status}.");
        }
    }

    private function assertKnownStepStatus(string $status): void
    {
        if (! array_key_exists($status, self::STEP_TRANSITIONS)) {
            throw new RuntimeException("Unknown automation step status {$status}.");
        }
    }

    private function isManualRetryOverride(string $fromStatus, string $toStatus, array $context): bool
    {
        return $fromStatus === ProductionAutomation::STEP_FAILED
            && $toStatus === ProductionAutomation::STEP_QUEUED
            && ($context['manual_override'] ?? false) === true;
    }

    private function isManualInvalidationOverride(string $fromStatus, string $toStatus, array $context): bool
    {
        return in_array($fromStatus, [
            ProductionAutomation::STEP_COMPLETED,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_FAILED_RECOVERABLE,
            ProductionAutomation::STEP_PROVIDER_FAILED,
        ], true)
            && $toStatus === ProductionAutomation::STEP_QUEUED
            && ($context['manual_invalidation'] ?? false) === true;
    }

    private function safeContext(array $context): array
    {
        unset($context['prompt'], $context['raw_response'], $context['private_path'], $context['provider_secret']);

        return $context;
    }
}

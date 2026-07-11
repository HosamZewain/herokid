<?php

namespace App\Services\ProductionStudio;

use App\Jobs\AdvanceProductionAutomationRun;
use App\Models\ProductionAutomationCostEntry;
use App\Models\SceneGenerationJob;
use Illuminate\Support\Facades\DB;

class ProductionAutomationProviderReconciler
{
    public function __construct(private readonly ProductionAutomationCostLedger $ledger) {}

    public function markSubmitted(SceneGenerationJob $job, ?string $providerRequestId = null): void
    {
        if (! $job->production_automation_run_id) {
            return;
        }

        DB::transaction(function () use ($job, $providerRequestId): void {
            $attempt = $job->automationAttempt()->lockForUpdate()->first();
            if ($attempt && in_array($attempt->status, ['queued', 'running'], true)) {
                $attempt->update([
                    'status' => 'submitted',
                    'provider_request_id' => $providerRequestId ?: $attempt->provider_request_id,
                    'heartbeat_at' => now(),
                    'submitted_at' => $attempt->submitted_at ?: now(),
                ]);
            }

            if ($providerRequestId && ($entryId = data_get($job->provider_request_json, 'automation_cost_entry_id'))) {
                $this->ledger->attachProviderRequest((int) $entryId, $providerRequestId);
            }
        });
    }

    public function markCompleted(SceneGenerationJob $job, ?string $actualCost = null, ?string $providerRequestId = null, bool $late = false): void
    {
        if (! $job->production_automation_run_id) {
            return;
        }

        DB::transaction(function () use ($job, $actualCost, $providerRequestId, $late): void {
            $entry = $this->costEntry($job);
            if ($entry) {
                $this->ledger->incur(
                    $entry,
                    $actualCost ?: $entry->estimated_amount,
                    $providerRequestId ?: $job->provider_request_id ?: $job->external_request_id,
                    actualProviderCostAvailable: filled($actualCost),
                    metadata: ['job_id' => $job->id, 'late_result' => $late]
                );
            }

            $attempt = $job->automationAttempt()->lockForUpdate()->first();
            if ($attempt && ! in_array($attempt->status, ['approved', 'failed', 'completed_late'], true)) {
                $attempt->update([
                    'status' => $late ? 'completed_late' : 'completed',
                    'provider_request_id' => $providerRequestId ?: $job->provider_request_id ?: $attempt->provider_request_id,
                    'output_fingerprint' => $job->output_fingerprint ?: $attempt->output_fingerprint,
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        });

        AdvanceProductionAutomationRun::dispatch($job->production_automation_run_id)->afterCommit();
    }

    public function markFailed(SceneGenerationJob $job, string $code, string $summary, bool $unknownExposure = false): void
    {
        if (! $job->production_automation_run_id) {
            return;
        }

        DB::transaction(function () use ($job, $code, $summary, $unknownExposure): void {
            $entry = $this->costEntry($job);
            if ($entry) {
                if ($unknownExposure) {
                    $this->ledger->markUnknownExposure($entry, $code);
                } else {
                    $this->ledger->release($entry, $code);
                }
            }

            $attempt = $job->automationAttempt()->lockForUpdate()->first();
            if ($attempt && ! in_array($attempt->status, ['approved', 'failed'], true)) {
                $attempt->update([
                    'status' => 'failed',
                    'safe_failure_code' => $code,
                    'safe_failure_summary' => $summary,
                    'heartbeat_at' => now(),
                    'failed_at' => now(),
                ]);
            }
        });

        AdvanceProductionAutomationRun::dispatch($job->production_automation_run_id)->afterCommit();
    }

    private function costEntry(SceneGenerationJob $job): ?ProductionAutomationCostEntry
    {
        $entryId = data_get($job->provider_request_json, 'automation_cost_entry_id');

        return $entryId ? ProductionAutomationCostEntry::find((int) $entryId) : null;
    }
}

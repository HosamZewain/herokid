<?php

namespace App\Services\ProductionStudio;

use App\Models\ProductionAutomationRun;
use App\Models\SceneGenerationJob;
use App\Support\ProductionAutomation;

class ProductionAutomationLateResultGuard
{
    public function canSubmit(SceneGenerationJob $job): bool
    {
        if (! $job->production_automation_run_id) {
            return true;
        }

        $run = $job->automationRun ?: ProductionAutomationRun::find($job->production_automation_run_id);

        return $run
            && $run->status === ProductionAutomation::STATUS_RUNNING
            && (int) $job->run_version === (int) $run->version
            && (int) $job->orchestration_generation === (int) $run->orchestration_generation;
    }

    public function canApplyResult(SceneGenerationJob $job): bool
    {
        if (! $job->production_automation_run_id) {
            return true;
        }

        $job->loadMissing(['automationRun', 'automationStep', 'automationAttempt']);
        $run = $job->automationRun;
        $step = $job->automationStep;
        $attempt = $job->automationAttempt;

        if (! $run || ! $step || ! $attempt) {
            return false;
        }

        if ($run->status !== ProductionAutomation::STATUS_RUNNING) {
            return false;
        }

        if ((int) $job->run_version !== (int) $run->version
            || (int) $job->orchestration_generation !== (int) $run->orchestration_generation
            || (int) $attempt->run_version !== (int) $run->version) {
            return false;
        }

        if (! in_array($step->status, [
            ProductionAutomation::STEP_QUEUED,
            ProductionAutomation::STEP_RUNNING,
            ProductionAutomation::STEP_WAITING_REVIEW,
            ProductionAutomation::STEP_PROVIDER_FAILED,
            ProductionAutomation::STEP_FAILED_RECOVERABLE,
        ], true)) {
            return false;
        }

        if ($job->input_fingerprint && $attempt->input_fingerprint && ! hash_equals($job->input_fingerprint, $attempt->input_fingerprint)) {
            return false;
        }

        return true;
    }
}

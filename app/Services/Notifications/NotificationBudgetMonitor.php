<?php

namespace App\Services\Notifications;

use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;

class NotificationBudgetMonitor
{
    public function __construct(
        private readonly AdminNotificationDispatcher $notifications,
        private readonly NotificationSettings $settings,
    ) {}

    public function checkAiJob(SceneGenerationJob $job): void
    {
        $actual = (float) ($job->actual_cost ?? $job->estimated_cost ?? 0);
        $threshold = $this->settings->float('notification_ai_job_warning_cost_usd', 0.20);

        if ($threshold <= 0 || $actual + 0.00001 < $threshold) {
            return;
        }

        $this->notifications->dispatchSafely('ai.generation.budget_exceeded', $job, [
            'dedupe_key' => 'ai.generation.budget_exceeded:'.$job->id,
            'threshold_usd' => number_format($threshold, 2, '.', ''),
            'current_cost_usd' => number_format($actual, 4, '.', ''),
            'status' => $job->status,
        ], 'critical');
    }

    public function checkProject(ProductionProject $project): void
    {
        $project->loadMissing('generationJobs');

        $budget = $this->projectBudget($project);
        $actual = (float) $project->generationJobs->sum(fn (SceneGenerationJob $job): float => (float) ($job->actual_cost ?? 0));
        $attempts = $project->generationJobs->count();

        if ($budget <= 0 || $actual <= 0) {
            return;
        }

        if ($this->settings->bool('notification_notify_on_budget_80_percent', true) && $actual + 0.00001 >= ($budget * 0.8)) {
            $this->notifications->dispatchSafely('production.project.budget_exceeded', $project, [
                'dedupe_key' => 'production.project.budget_80:'.$project->id,
                'threshold' => '80_percent',
                'budget_usd' => number_format($budget, 2, '.', ''),
                'current_cost_usd' => number_format($actual, 4, '.', ''),
                'attempts' => $attempts,
                'status' => $project->status,
            ], 'warning');
        }

        if ($actual + 0.00001 >= $budget) {
            $this->notifications->dispatchSafely('production.project.budget_exceeded', $project, [
                'dedupe_key' => 'production.project.budget_exceeded:'.$project->id,
                'threshold' => 'exceeded',
                'budget_usd' => number_format($budget, 2, '.', ''),
                'current_cost_usd' => number_format($actual, 4, '.', ''),
                'attempts' => $attempts,
                'status' => $project->status,
            ], 'critical');
        }
    }

    private function projectBudget(ProductionProject $project): float
    {
        $projectOverride = data_get($project->source_snapshot_json, 'notification_budget_usd');

        if ($projectOverride !== null && (float) $projectOverride > 0) {
            return (float) $projectOverride;
        }

        return $this->settings->float('notification_ai_project_warning_cost_usd',
            $this->settings->float('notification_production_default_ai_budget_usd', 2.00)
        );
    }
}

<?php

namespace App\Services\ProductAutomation;

use App\Models\PuzzleGeneration;

/** SELECT-only proposal. Admission still needs a fresh Auto Pilot decision. */
final class ImageRecoveryPolicy
{
    public function forRun(PuzzleGeneration $run): array
    {
        $failure = $run->context['failure'] ?? [];
        $attempt = (int) ($run->context['automatic_recovery']['attempt'] ?? 0);
        $category = $failure['category'] ?? ($run->status === 'blocked' && ! $run->started_at ? 'input_preflight' : 'legacy_unknown');
        $reason = match ($category) {
            'input_preflight' => 'IMAGE_INPUTS_REVIEW_REQUIRED',
            'content_refusal' => 'IMAGE_CONTENT_REVIEW_REQUIRED',
            'billing', 'credentials', 'model_configuration' => 'IMAGE_PROVIDER_CONFIGURATION_REQUIRED',
            'request_rejected', 'reference_format' => 'IMAGE_REQUEST_REVIEW_REQUIRED',
            default => 'IMAGE_RESULT_RECONCILIATION_REQUIRED',
        };
        $eligible = ($failure['version'] ?? null) === 1 && ($failure['definitively_rejected'] ?? false) === true
            && in_array($category, ['rate_limit', 'reference_format'], true)
            && $run->status === 'blocked' && ! $run->requested_by && $run->finished_at && ! $run->lease_until
            && ! $run->output_path && ! $run->preview_id && ! $run->attachment_id;
        if ($attempt >= 2 || $run->attempt_number >= 3 || ($category === 'reference_format' && $attempt > 0)) {
            $eligible = false;
            $reason = 'IMAGE_RECOVERY_LIMIT_REACHED';
        }
        $due = $eligible ? $run->finished_at->copy()->addSeconds($attempt === 0 ? 60 : 300) : null;

        return ['eligible' => (bool) ($eligible && $due->isPast()), 'reason_code' => $eligible ? ($due->isPast() ? 'IMAGE_RECOVERY_READY' : 'IMAGE_RECOVERY_BACKOFF') : $reason,
            'retry_at' => $due?->toIso8601String(), 'attempt' => $attempt, 'category' => $category];
    }
}

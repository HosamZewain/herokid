<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityRequest;

class ChildIdentityAggregateService
{
    public function recalculate(ChildIdentityRequest $identity): void
    {
        $attempts = $identity->attempts()->get(['status', 'cost_usd', 'cost_egp', 'billing_status']);
        $knownUsd = $attempts->whereNotNull('cost_usd');
        $knownEgp = $attempts->whereNotNull('cost_egp');
        $hasUnknownUsd = $attempts->contains(
            fn ($attempt) => $attempt->cost_usd === null && $attempt->billing_status === 'unknown'
        );

        $identity->forceFill([
            'total_attempts' => $attempts->count(),
            'successful_attempts' => $identity->attempts()->whereNotNull('output_storage_path')->count(),
            'failed_attempts' => $attempts->where('status', 'failed')->count(),
            'total_cost_usd' => $hasUnknownUsd || $knownUsd->isEmpty()
                ? null
                : $knownUsd->sum(fn ($attempt) => (float) $attempt->cost_usd),
            'total_cost_egp' => $knownEgp->isEmpty() ? null : $knownEgp->sum(fn ($attempt) => (float) $attempt->cost_egp),
            'last_activity_at' => now(),
        ])->saveQuietly();
    }
}

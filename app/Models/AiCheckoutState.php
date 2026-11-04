<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCheckoutState extends Model
{
    protected $guarded = [];

    protected $casts = ['snapshot' => 'encrypted:array', 'covered_targets' => 'array', 'context_version' => 'integer', 'target_count' => 'integer',
        'ready_target_count' => 'integer', 'ready_since' => 'datetime', 'blocked' => 'boolean', 'dirty_at' => 'datetime', 'next_evaluation_at' => 'datetime', 'observed_at' => 'datetime', 'evaluated_at' => 'datetime', 'last_attempt_at' => 'datetime'];

    /** Call under the state lock after updating coverage; this does not save. */
    public function trackReadyTargets(array $targets): void
    {
        $covered = $this->covered_targets ?? [];
        $this->ready_target_count = count(array_filter($targets, fn ($target) => $target['decision'] === 'execute'
            && ! in_array($target['target_id'], $covered, true)));
        $this->ready_since = $this->ready_target_count > 0 ? ($this->ready_since ?? now()) : null;
    }
}

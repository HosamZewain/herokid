<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionAutomationCostEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'estimated_amount' => 'decimal:4',
        'actual_amount' => 'decimal:4',
        'pricing_snapshot' => 'array',
        'metadata_json' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'automation_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationStep::class, 'automation_step_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationAttempt::class, 'attempt_id');
    }

    public function releasedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'released_from_cost_entry_id');
    }
}

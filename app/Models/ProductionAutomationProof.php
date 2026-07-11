<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionAutomationProof extends Model
{
    protected $guarded = [];

    protected $casts = [
        'checklist_snapshot' => 'array',
        'print_test_metadata' => 'array',
        'reviewed_at' => 'datetime',
        'report_generated_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'automation_run_id');
    }

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'current_run_id');
    }

    public function layout(): BelongsTo
    {
        return $this->belongsTo(ProductionPrintLayout::class, 'production_print_layout_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isCurrent(): bool
    {
        return $this->current_run_id !== null;
    }

    public function hasReport(): bool
    {
        return $this->report_status === 'ready'
            && filled($this->report_path)
            && filled($this->report_checksum);
    }
}

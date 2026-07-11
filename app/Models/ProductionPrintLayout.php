<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionPrintLayout extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings_json' => 'array',
        'manifest_json' => 'array',
        'generated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'production_automation_run_id');
    }

    public function automationStep(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationStep::class, 'production_automation_step_id');
    }

    public function automationAttempt(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationAttempt::class, 'production_automation_attempt_id');
    }

    public function automationProofs(): HasMany
    {
        return $this->hasMany(ProductionAutomationProof::class, 'production_print_layout_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready'
            && filled($this->reader_pdf_path)
            && filled($this->print_pdf_path)
            && filled($this->manifest_path)
            && filled($this->proof_checklist_path);
    }

    public function isValidatedAutomationReady(): bool
    {
        return $this->isReady()
            && filled($this->output_fingerprint)
            && data_get($this->manifest_json, 'validation.ok') === true;
    }
}

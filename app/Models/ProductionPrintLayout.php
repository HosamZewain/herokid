<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function isReady(): bool
    {
        return $this->status === 'ready'
            && filled($this->reader_pdf_path)
            && filled($this->print_pdf_path);
    }
}

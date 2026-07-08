<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'generation_capabilities_json' => 'array',
        'estimated_cost_per_output' => 'decimal:4',
        'estimated_cost_amount' => 'decimal:4',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'configuration_json' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->generation_capabilities_json ?? [], true);
    }

    public function estimatedCost(): string
    {
        return (string) ($this->estimated_cost_amount ?? $this->estimated_cost_per_output ?? '0.0000');
    }

    public function requiresImageUrl(): bool
    {
        return (bool) data_get($this->configuration_json, 'requires_image_url', false);
    }

    public function supportsMultipleReferences(): bool
    {
        return (bool) data_get($this->configuration_json, 'supports_multiple_references', false);
    }

    public function supportsTextToImageOnly(): bool
    {
        return (bool) data_get($this->configuration_json, 'supports_text_to_image_only', true);
    }

    public function supportsImageEditing(): bool
    {
        return (bool) data_get($this->configuration_json, 'supports_image_editing', false);
    }
}

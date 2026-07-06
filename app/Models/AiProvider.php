<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'supports_text_to_image' => 'boolean',
        'supports_image_to_image' => 'boolean',
        'supports_editing' => 'boolean',
        'supports_upscaling' => 'boolean',
    ];

    public function models()
    {
        return $this->hasMany(AiModel::class);
    }
}

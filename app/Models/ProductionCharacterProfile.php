<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionCharacterProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reference_photo_selection' => 'array',
        'approved_reference_photos' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }
}

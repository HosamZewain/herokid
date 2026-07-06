<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionProjectAsset extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

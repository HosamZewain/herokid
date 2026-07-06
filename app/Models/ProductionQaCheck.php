<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionQaCheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'override_allowed' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}

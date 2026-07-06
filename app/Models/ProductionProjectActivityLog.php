<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionProjectActivityLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

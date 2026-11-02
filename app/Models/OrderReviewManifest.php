<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderReviewManifest extends Model
{
    protected $guarded = [];

    protected $casts = ['images' => 'encrypted:array', 'approved_at' => 'datetime'];

    public function run()
    {
        return $this->belongsTo(OrderProcessRun::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageStoreSection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingPackageProduct extends Model
{
    protected $guarded = [];

    public function package()
    {
        return $this->belongsTo(PricingPackage::class, 'pricing_package_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

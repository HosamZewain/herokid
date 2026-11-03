<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPreviewStaffApproval extends Model
{
    protected $guarded = [];

    protected $casts = ['note' => 'encrypted', 'production_inputs' => 'encrypted:array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function matches(OrderProcessRun $run): bool
    {
        $manifest = OrderReviewManifest::where('run_id', $run->id)->first();

        return $this->exists && $manifest && $manifest->id === $this->manifest_id
            && hash_equals($manifest->fingerprint, $this->fingerprint)
            && OrderReviewManifest::where('checkout_key', $run->checkout_key)->max('id') === $manifest->id;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPaymentProof extends Model
{
    protected $guarded = [];

    protected $hidden = ['file_path'];

    protected $casts = [
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}

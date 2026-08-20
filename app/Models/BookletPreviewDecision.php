<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookletPreviewDecision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'comments' => 'encrypted',
        'decided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function preview()
    {
        return $this->belongsTo(BookletPreview::class, 'booklet_preview_id');
    }

    public function version()
    {
        return $this->belongsTo(BookletPreviewVersion::class, 'booklet_preview_version_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

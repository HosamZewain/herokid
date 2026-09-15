<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminDashboardNoteAttachment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size' => 'integer',
    ];

    public function note(): BelongsTo
    {
        return $this->belongsTo(AdminDashboardNote::class, 'admin_dashboard_note_id');
    }
}

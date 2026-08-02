<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookletPreviewVersion extends Model
{
    protected $guarded = [];

    protected $hidden = ['file_path'];

    public function preview(): BelongsTo
    {
        return $this->belongsTo(BookletPreview::class, 'booklet_preview_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

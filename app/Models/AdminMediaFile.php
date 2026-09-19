<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminMediaFile extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function publicUrl(): string
    {
        return route('media-library.public', $this);
    }

    public function category(): string
    {
        if ($this->mime_type === 'application/pdf') {
            return 'pdf';
        }

        if (str_starts_with($this->mime_type, 'image/')) {
            return 'image';
        }

        return 'text';
    }
}

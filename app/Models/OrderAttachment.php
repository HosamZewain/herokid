<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OrderAttachment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'validity_days' => 'integer',
        'size' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->isPast();
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = max(0, (int) $this->size);

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    public function getIconAttribute(): string
    {
        return str_starts_with((string) $this->mime_type, 'image/') ? '🖼️' : '📄';
    }

    protected static function booted(): void
    {
        static::deleting(function (self $attachment): void {
            Storage::disk($attachment->disk ?: 'local')->delete($attachment->path);
        });
    }
}

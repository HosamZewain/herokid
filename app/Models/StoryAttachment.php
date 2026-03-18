<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StoryAttachment extends Model
{
    protected $guarded = [];

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    /** Human-readable file size */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /** Icon class based on MIME type */
    public function getIconAttribute(): string
    {
        $mime = $this->mime_type ?? '';
        if (str_contains($mime, 'pdf'))   return '📄';
        if (str_contains($mime, 'image')) return '🖼️';
        if (str_contains($mime, 'word') || str_contains($mime, 'document')) return '📝';
        if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return '📊';
        if (str_contains($mime, 'zip') || str_contains($mime, 'rar')) return '🗜️';
        return '📎';
    }

    /** Delete the physical file when the model is deleted */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            Storage::disk('local')->delete($attachment->path);
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LibraryStory extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['disk', 'pdf_path', 'cover_path', 'updated_by'];

    protected $casts = ['published_at' => 'datetime', 'page_count' => 'integer', 'file_size' => 'integer', 'age_min' => 'integer', 'age_max' => 'integer'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}

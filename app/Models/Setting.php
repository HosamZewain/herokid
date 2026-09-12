<?php

namespace App\Models;

use App\Support\RequestSettings;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => RequestSettings::forget());
        static::deleted(fn () => RequestSettings::forget());
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

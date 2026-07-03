<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

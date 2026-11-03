<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemory extends Model
{
    protected $guarded = [];

    protected $casts = ['guidance' => 'encrypted', 'revision' => 'integer'];

    public function versions()
    {
        return $this->hasMany(AiMemoryVersion::class, 'memory_id');
    }
}

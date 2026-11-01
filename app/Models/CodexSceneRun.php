<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodexSceneRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['snapshot' => 'encrypted:array', 'scenes' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

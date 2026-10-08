<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseExport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime', 'size' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

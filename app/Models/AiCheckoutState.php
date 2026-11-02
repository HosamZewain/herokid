<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCheckoutState extends Model
{
    protected $guarded = [];
    protected $casts = ['snapshot' => 'encrypted:array', 'covered_targets' => 'array', 'context_version' => 'integer', 'target_count' => 'integer',
        'blocked' => 'boolean', 'dirty_at' => 'datetime', 'next_evaluation_at' => 'datetime', 'observed_at' => 'datetime', 'evaluated_at' => 'datetime', 'last_attempt_at' => 'datetime'];
}

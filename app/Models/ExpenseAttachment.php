<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseAttachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ExpenseTransaction::class, 'transaction_id');
    }
}

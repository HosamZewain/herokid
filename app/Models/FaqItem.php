<?php

namespace App\Models;

use App\Services\Catalog\CustomerKnowledge;
use Illuminate\Database\Eloquent\Model;

class FaqItem extends Model
{
    protected $guarded = [];

    public function getCustomerAnswerAttribute(): string
    {
        return app(CustomerKnowledge::class)->render((string) $this->answer);
    }

    protected $casts = [
        'active' => 'boolean',
        'show_on_packages' => 'boolean',
    ];
}

<?php

namespace App\DTOs\Ai;

class MoneyValue
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency = 'USD',
        public readonly string $source = 'estimate',
    ) {}
}

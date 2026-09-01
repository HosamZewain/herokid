<?php

namespace App\Support;

/** @deprecated Use AppDateTime. Kept for backward compatibility. */
class OrderDateTime extends AppDateTime
{
    public static function timezone(): string
    {
        return (string) config('orders.display_timezone', parent::timezone());
    }
}

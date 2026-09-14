<?php

namespace App\Support;

class OrderLifecycle
{
    /**
     * Payment behaviors that no longer keep a fully fulfilled checkout in the
     * active operations queue. Any outstanding carrier COD remains an
     * operational shipping amount and does not rewrite the recorded payment.
     *
     * @return array<int, string>
     */
    public static function completePaymentBehaviors(): array
    {
        return [
            OrderPaymentStatus::PARTIALLY_PAID,
            OrderPaymentStatus::PAID_WITHOUT_SHIPPING,
            OrderPaymentStatus::PAID_IN_FULL,
        ];
    }

    public static function isPaymentComplete(?string $status): bool
    {
        return in_array(
            OrderPaymentStatus::behavior($status),
            self::completePaymentBehaviors(),
            true,
        );
    }
}

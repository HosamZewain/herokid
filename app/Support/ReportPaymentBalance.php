<?php

namespace App\Support;

/** Read-only report classification; never changes the saved payment status. */
final class ReportPaymentBalance
{
    public static function fullyPaid(int $totalCents, int $paidCents, bool $cancelled): bool
    {
        return ! $cancelled && $paidCents > 0 && $paidCents >= $totalCents;
    }
}

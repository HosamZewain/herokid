<?php

namespace App\Services\ProductAutomation;

/** Shared stage contract for Auto Pilot first admission and bounded image recovery. */
final class ProductImageAdmission
{
    public const REASONS = ['PRODUCT_ORDER_STAGE_REQUIRES_REVIEW', 'PRODUCT_PRINTING_STAGE_REQUIRES_REVIEW', 'PRODUCT_SHIPPING_STAGE_REQUIRES_REVIEW'];

    public static function reason(?string $status, ?string $printing, ?string $shipping): ?string
    {
        return match (true) {
            ! in_array($status, ['new_confirmed', 'costomer_confirmed'], true) => self::REASONS[0],
            ! in_array($printing, [null, 'not_started', 'not_required'], true) => self::REASONS[1],
            ! in_array($shipping, [null, 'not_ready', 'not_required'], true) => self::REASONS[2],
            default => null,
        };
    }
}

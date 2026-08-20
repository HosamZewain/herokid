<?php

namespace App\Support;

class MobileAnalyticsEventRegistry
{
    public const EVENTS = [
        'app_opened', 'onboarding_completed', 'registration_started', 'registration_completed',
        'child_profile_created', 'photo_upload_started', 'photo_upload_completed', 'photo_upload_failed',
        'identity_generation_started', 'identity_generation_completed', 'identity_shared', 'product_viewed',
        'personalization_started', 'personalization_completed', 'product_added_to_cart', 'checkout_started',
        'payment_completed', 'order_completed', 'preview_viewed', 'preview_approved', 'revision_requested',
        'product_reordered', 'review_submitted',
    ];

    public static function sanitize(array $properties): array
    {
        $safe = [];
        foreach ($properties as $key => $value) {
            $key = (string) $key;
            $allowedChildIdentifier = in_array($key, ['child_profile_id', 'child_identity_id'], true);
            if (! $allowedChildIdentifier && preg_match('/child|photo|image|name|email|phone|address|token|path|url/i', $key)) {
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 250);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
            if (count($safe) >= 25) {
                break;
            }
        }

        return $safe;
    }
}

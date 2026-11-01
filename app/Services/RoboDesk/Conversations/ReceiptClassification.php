<?php

namespace App\Services\RoboDesk\Conversations;

use Carbon\CarbonImmutable;

/** Provider document classification is review evidence, never payment authorization. */
final class ReceiptClassification
{
    public static function fromMessage(array $message): ?array
    {
        $value = $message['classification'] ?? null;
        if (($message['direction'] ?? null) !== 'in'
            || ! in_array($message['type'] ?? null, ['image', 'document', 'file'], true)
            || ! is_array($message['attachments'] ?? null) || $message['attachments'] === []
            || ! is_array($value) || ($value['type'] ?? null) !== 'payment_receipt'
            || ($value['source'] ?? null) !== 'ai') {
            return null;
        }
        $at = $value['at'] ?? null;
        // Unknown/malformed additive metadata must not break existing history sync.
        // Require an actual timezone and calendar date, not a relative date expression.
        if (! is_string($at) || strlen($at) > 40
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $at)) {
            return null;
        }
        try {
            $parsed = CarbonImmutable::parse($at);
            if ($parsed->format('Y-m-d\TH:i:s') !== substr($at, 0, 19)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // Deliberately discard extra fields (amounts, URLs, notes, purported approval).
        return ['type' => 'payment_receipt', 'source' => 'ai', 'at' => $parsed->utc()->toISOString()];
    }
}

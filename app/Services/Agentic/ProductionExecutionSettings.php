<?php

namespace App\Services\Agentic;

use Carbon\CarbonImmutable;

final class ProductionExecutionSettings
{
    public const ACTIONS = ['generate_product_image', 'generate_book_cover', 'start_story_scene_generation', 'generate_book_pages', 'send_order_confirmation', 'send_product_preview'];

    /** Invalid or incomplete activation fails closed; no default-wide rollout. */
    public function approved(): ?array
    {
        $c = config('agentic.execution', []);
        if (($c['enabled'] ?? false) !== true || ! preg_match('/\A[a-zA-Z0-9_-]{8,80}\z/', $c['activation_id'] ?? '')
            || ! is_array($c['actions'] ?? null) || ! $c['actions'] || array_diff($c['actions'], self::ACTIONS)
            || ! is_array($c['order_ids'] ?? null) || count($c['order_ids']) > 1000
            || ! is_int($c['daily_limit'] ?? null) || $c['daily_limit'] < 1 || $c['daily_limit'] > 1000) {
            return null;
        }
        foreach ($c['order_ids'] as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                return null;
            }
        }
        if (($c['all_checkouts'] ?? false) !== true && ! $c['order_ids']) {
            return null;
        }
        try {
            if (! is_string($c['activated_at'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})\z/', $c['activated_at'])) {
                return null;
            }
            $c['since'] = CarbonImmutable::parse($c['activated_at'])->utc();
            if ($c['since']->isFuture()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $c;
    }

    public function includes(array $settings, int $representativeId): bool
    {
        return ($settings['all_checkouts'] ?? false) === true || in_array($representativeId, array_map('intval', $settings['order_ids']), true);
    }
}

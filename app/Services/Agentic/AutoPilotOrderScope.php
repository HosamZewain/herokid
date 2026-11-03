<?php

namespace App\Services\Agentic;

use App\Services\Orders\AdminOrderGroupService;

final class AutoPilotOrderScope
{
    public static function activeOrdersQuery()
    {
        return app(AdminOrderGroupService::class)->activeOrdersQuery()->whereNotIn('status', ['delivered', 'cancelled']);
    }

    public static function complete(array $context): bool
    {
        return $context['all_complete'] || (! empty($context['orders']) && collect($context['orders'])
            ->every(fn ($order) => ($order['deleted'] ?? false) || in_array($order['status'], ['delivered', 'cancelled'], true)));
    }
}

<?php

namespace App\Services\AdminAssistant;

use Illuminate\Support\Arr;

final class AssistantPageContext
{
    public const FILTERS = ['range', 'start_date', 'end_date', 'status', 'payment_status', 'type', 'item', 'customer_type', 'country_id', 'governorate_id', 'source', 'q', 'min_total', 'max_total'];

    public function resolve(string $path, array $query, array $tools): array
    {
        // Client context selects a resource; never fetch client URLs, page HTML or arbitrary routes.
        $tool = match ($path) {
            '/admin/agentic-automation' => 'autopilot',
            '/admin/orders' => 'queue',
            '/admin/sales-report' => 'sales',
            '/admin/payment-report' => 'payments',
            default => 'help',
        };
        $reference = null;
        if (preg_match('~^/admin/(?:orders|agentic-automation/orders)/(\d+)$~D', $path, $m)) {
            $tool = 'order';
            $reference = $m[1];
        }
        if (! in_array($tool, $tools, true)) {
            return ['tool' => 'help', 'reference' => null, 'filters' => [], 'unsupported_filters' => []];
        }
        $filters = in_array($tool, ['sales', 'payments'], true) ? Arr::only($query, self::FILTERS) : [];
        // Never silently claim to explain a filtered page while dropping a filter.
        $unsupported = array_values(array_diff(array_keys($query), [...array_keys($filters), 'page', 'per_page', 'sort', 'group_by']));

        return ['tool' => $tool, 'reference' => $reference, 'filters' => $filters, 'unsupported_filters' => $unsupported];
    }
}

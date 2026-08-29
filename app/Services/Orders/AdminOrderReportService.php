<?php

namespace App\Services\Orders;

use App\Support\OrderPaymentStatus;
use App\Support\OrderSource;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminOrderReportService
{
    public function __construct(private readonly AdminOrderGroupService $groups) {}

    public function report(Request $request): array
    {
        $request->attributes->set('order_report', true);
        $request->query->set('catalog_type', $this->catalogType($request));
        $request->query->set('lifecycle', $this->lifecycle($request));

        $rows = $this->groups->export($request)
            ->map(fn (array $row): array => $this->decorate($row));

        return [
            'rows' => $rows,
            'summary' => $this->summary($rows),
            'breakdowns' => [
                'catalog' => $this->breakdown($rows, 'catalog_type_label'),
                'lifecycle' => $this->breakdown($rows, 'lifecycle_label'),
                'status' => $this->breakdown($rows, 'status_label'),
                'payment' => $this->breakdown($rows, 'payment_status_label'),
                'printing' => $this->breakdown($rows, 'printing_status_label'),
                'shipping' => $this->breakdown($rows, 'shipping_status_label'),
                'source' => $this->breakdown($rows, 'order_source_label'),
                'daily' => $this->dailyBreakdown($rows),
            ],
            'options' => [
                'statuses' => OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_ORDER, false),
                'payment_statuses' => OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_PAYMENT, false),
                'printing_statuses' => OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_PRINTING, false),
                'shipping_statuses' => OrderStatusRegistry::labels(OrderStatusRegistry::TYPE_SHIPPING, false),
                'sources' => OrderSource::options(),
                'payment_methods' => OrderPaymentStatus::paymentMethods(),
            ],
        ];
    }

    private function decorate(array $row): array
    {
        $cancelled = (bool) $row['trashed'] || (collect($row['statuses'])->isNotEmpty()
            && collect($row['statuses'])->every(
                fn (string $status): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status) === 'cancelled'
            ));
        $finished = ! $cancelled && $this->isFinished($row);
        $lifecycle = $cancelled ? 'cancelled' : ($finished ? 'finished' : 'active');

        return [
            ...$row,
            'catalog_type' => $row['story_count'] > 0 ? 'stories' : 'products',
            'catalog_type_label' => $row['story_count'] > 0 ? 'طلبات قصص' : 'طلبات منتجات',
            'lifecycle' => $lifecycle,
            'lifecycle_label' => match ($lifecycle) {
                'cancelled' => 'ملغاة / محذوفة',
                'finished' => 'منتهية',
                default => 'نشطة',
            },
            'order_source_label' => OrderSource::label($row['order_source']),
        ];
    }

    private function isFinished(array $row): bool
    {
        $orderDone = collect($row['statuses'])->isNotEmpty()
            && collect($row['statuses'])->every(
                fn (string $status): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status) === 'delivered'
            );
        $paymentDone = OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PAYMENT, $row['payment_status']) === 'paid_in_full';
        $printingDone = in_array(
            OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PRINTING, $row['printing_status']),
            ['completed', 'not_required'],
            true,
        );
        $shippingDone = in_array(
            OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $row['shipping_status']),
            ['delivered', 'not_required'],
            true,
        );

        return $orderDone && $paymentDone && $printingDone && $shippingDone;
    }

    private function summary(Collection $rows): array
    {
        $cancelled = $rows->where('lifecycle', 'cancelled');
        $active = $rows->where('lifecycle', 'active');
        $finished = $rows->where('lifecycle', 'finished');
        $paidAny = $rows->where('paid_amount_cents', '>', 0);
        $fullyPaid = $rows->filter(fn (array $row): bool => OrderStatusRegistry::behavior(
            OrderStatusRegistry::TYPE_PAYMENT,
            $row['payment_status'],
        ) === 'paid_in_full');
        $shipped = $rows->filter(fn (array $row): bool => in_array(
            OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $row['shipping_status']),
            ['shipped', 'delivered'],
            true,
        ));
        $delivered = $rows->filter(fn (array $row): bool => OrderStatusRegistry::behavior(
            OrderStatusRegistry::TYPE_SHIPPING,
            $row['shipping_status'],
        ) === 'delivered');

        return [
            'checkouts' => $rows->count(),
            'order_records' => (int) $rows->sum(fn (array $row): int => count($row['order_numbers'])),
            'stories' => (int) $rows->sum('story_count'),
            'products' => (int) $rows->sum(fn (array $row): int => $row['product_quantity'] + $row['add_on_quantity']),
            'items_cents' => (int) $rows->sum('items_cents'),
            'delivery_cents' => (int) $rows->sum('delivery_cents'),
            'discount_cents' => (int) $rows->sum('discount_cents'),
            'total_cents' => (int) $rows->sum('total_cents'),
            'paid_amount_cents' => (int) $rows->sum('paid_amount_cents'),
            'remaining_amount_cents' => (int) $rows->sum('remaining_amount_cents'),
            'average_order_cents' => $rows->isEmpty() ? 0 : (int) round($rows->avg('total_cents')),
            'active_checkouts' => $active->count(),
            'finished_checkouts' => $finished->count(),
            'cancelled_checkouts' => $cancelled->count(),
            'cancelled_value_cents' => (int) $cancelled->sum('total_cents'),
            'cancelled_paid_cents' => (int) $cancelled->sum('paid_amount_cents'),
            'paid_checkouts' => $paidAny->count(),
            'fully_paid_checkouts' => $fullyPaid->count(),
            'shipped_checkouts' => $shipped->count(),
            'delivered_checkouts' => $delivered->count(),
        ];
    }

    private function breakdown(Collection $rows, string $key): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row[$key] ?: 'غير محدد'))
            ->map(fn (Collection $group, string $label): array => [
                'label' => $label,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
                'paid_cents' => (int) $group->sum('paid_amount_cents'),
            ])
            ->sortByDesc('count')
            ->values();
    }

    private function dailyBreakdown(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => $row['created_at']?->timezone((string) config('app.timezone', 'Africa/Cairo'))->format('Y-m-d') ?? 'غير محدد')
            ->map(fn (Collection $group, string $date): array => [
                'label' => $date,
                'count' => $group->count(),
                'total_cents' => (int) $group->sum('total_cents'),
                'paid_cents' => (int) $group->sum('paid_amount_cents'),
            ])
            ->sortByDesc('label')
            ->values();
    }

    private function catalogType(Request $request): string
    {
        $type = (string) $request->query('catalog_type', 'all');

        return in_array($type, ['all', 'stories', 'products'], true) ? $type : 'all';
    }

    private function lifecycle(Request $request): string
    {
        $lifecycle = (string) $request->query('lifecycle', 'all');

        return in_array($lifecycle, ['all', 'active', 'finished', 'cancelled'], true) ? $lifecycle : 'all';
    }
}

<?php

namespace App\Services\Orders;

use App\Support\AppDateTime;
use App\Support\OrderLifecycle;
use App\Support\OrderPaymentStatus;
use App\Support\OrderSource;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AdminOrderReportService
{
    public function __construct(private readonly AdminOrderGroupService $groups) {}

    public function report(Request $request, bool $paginate = false): array
    {
        $this->prepareRequest($request);
        // Only scalar checkout facts are needed for statistics, never production models.
        $rows = $paginate ? $this->groups->reportFacts($request)->map(function (object $fact): array {
            $statuses = [$fact->status];

            return $this->decorate([
                'statuses' => $statuses,
                'status_label' => $fact->status === 'mixed' ? 'حالات متعددة' : OrderStatusRegistry::label(OrderStatusRegistry::TYPE_ORDER, $fact->status),
                '_all_cancelled' => (bool) $fact->all_cancelled,
                '_all_delivered' => (bool) $fact->all_delivered,
                'trashed' => ! $fact->has_live_orders,
                'story_count' => (int) $fact->stories,
                'product_quantity' => (int) $fact->products,
                'add_on_quantity' => 0,
                'order_records' => (int) $fact->order_records,
                'items_cents' => (int) ($fact->item_cents == 0 ? $fact->legacy_cents : $fact->item_cents),
                'delivery_cents' => (int) $fact->delivery_cents,
                'discount_cents' => (int) $fact->discount_cents,
                'total_cents' => (int) $fact->total_cents,
                'paid_amount_cents' => (int) $fact->paid_amount_cents,
                'remaining_amount_cents' => max(0, (int) $fact->total_cents - (int) $fact->paid_amount_cents),
                'payment_status' => $fact->payment_status,
                'payment_status_label' => OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PAYMENT, $fact->payment_status),
                'printing_status' => $fact->printing_status,
                'printing_status_label' => $fact->printing_status === 'mixed' ? 'حالات طباعة متعددة' : OrderStatusRegistry::label(OrderStatusRegistry::TYPE_PRINTING, $fact->printing_status),
                'shipping_status' => $fact->shipping_status,
                'shipping_status_label' => $fact->shipping_status === 'mixed' ? 'حالات شحن متعددة' : OrderStatusRegistry::label(OrderStatusRegistry::TYPE_SHIPPING, $fact->shipping_status),
                'order_source' => $fact->order_source ?: 'website',
                'created_at' => $fact->first_created_at,
            ]);
        }) : $this->rows($request);

        $perPage = in_array($request->integer('per_page', 25), [25, 50, 100], true) ? $request->integer('per_page', 25) : 25;

        return [
            'rows' => $paginate ? $this->groups->reportPage($request, $perPage)
                ->through(fn (array $row): array => $this->decorate($row)) : $rows,
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
                'tags' => $this->groups->tagOptions(),
            ],
        ];
    }

    public function rows(Request $request): Collection
    {
        $this->prepareRequest($request);

        return $this->groups->export($request)->map(fn (array $row): array => $this->decorate($row));
    }

    public function export(Request $request): array
    {
        $this->prepareRequest($request);
        $export = $this->groups->reportExport($request);
        $export['rows'] = $export['rows']->map(fn (array $row): array => $this->decorate($row));

        return $export;
    }

    private function prepareRequest(Request $request): void
    {
        $request->attributes->set('order_report', true);
        $request->query->set('catalog_type', $this->catalogType($request));
        $request->query->set('lifecycle', $this->lifecycle($request));
    }

    private function decorate(array $row): array
    {
        $cancelled = (bool) $row['trashed'] || ($row['_all_cancelled'] ?? (collect($row['statuses'])->isNotEmpty()
            && collect($row['statuses'])->every(
                fn (string $status): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status) === 'cancelled'
            )));
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
        $orderDone = $row['_all_delivered'] ?? (collect($row['statuses'])->isNotEmpty()
            && collect($row['statuses'])->every(
                fn (string $status): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status) === 'delivered'
            ));
        $paymentDone = OrderLifecycle::isPaymentComplete($row['payment_status']);
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
            'order_records' => (int) $rows->sum(fn (array $row): int => $row['order_records'] ?? count($row['order_numbers'])),
            'stories' => (int) $rows->sum('story_count'),
            'products' => (int) $rows->sum(fn (array $row): int => $row['product_quantity'] + $row['add_on_quantity']),
            'items_cents' => (int) $rows->sum('items_cents'),
            'delivery_cents' => (int) $rows->sum('delivery_cents'),
            'discount_cents' => (int) $rows->sum('discount_cents'),
            'total_cents' => (int) $rows->sum('total_cents'),
            'paid_amount_cents' => (int) $rows->sum('paid_amount_cents'),
            'remaining_amount_cents' => (int) $rows->sum('remaining_amount_cents'),
            'average_order_cents' => $rows->isEmpty() ? 0 : (int) round($rows->avg(
                fn (array $row): int => max(0, (int) $row['items_cents'] - (int) $row['discount_cents'])
            )),
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
            ->groupBy(fn (array $row): string => AppDateTime::format($row['created_at'], 'Y-m-d', 'غير محدد'))
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

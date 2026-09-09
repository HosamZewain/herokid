<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderBulkActionService
{
    public const ACTION_UPDATE_STATUS = 'update_status';

    public const ACTION_RELEASE_ASSIGNMENTS = 'release_assignments';

    public const ACTION_UPDATE_STATUS_AND_RELEASE = 'update_status_and_release';

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly OrderAssignmentService $assignments,
    ) {}

    /**
     * @param  array<int, int|string>  $representativeIds
     * @return array{checkout_count: int, status_updated_count: int, released_assignment_count: int}
     */
    public function execute(
        array $representativeIds,
        string $action,
        ?string $status,
        ?string $notes,
        User $admin,
        Request $request,
    ): array {
        $ids = collect($representativeIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($ids, $action, $status, $notes, $admin, $request): array {
            $representatives = Order::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($representatives->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'representative_ids' => 'أحد الطلبات المحددة غير موجود أو تم حذفه. حدّث الصفحة وحاول مرة أخرى.',
                ]);
            }

            $representatives = $representatives
                ->unique(fn (Order $order): string => $order->checkoutGroupKey())
                ->values();
            $changesStatus = in_array($action, [self::ACTION_UPDATE_STATUS, self::ACTION_UPDATE_STATUS_AND_RELEASE], true);
            $releasesAssignments = in_array($action, [self::ACTION_RELEASE_ASSIGNMENTS, self::ACTION_UPDATE_STATUS_AND_RELEASE], true);
            $statusUpdatedCount = 0;
            $releasedAssignmentCount = 0;

            foreach ($representatives as $representative) {
                $orders = $this->activeOrdersForCheckout($representative);

                if ($changesStatus) {
                    $this->statuses->updateGroup($orders, (string) $status, $notes, $request);
                    $statusUpdatedCount++;
                }

                if ($releasesAssignments) {
                    $wasAssigned = OrderGroupAssignment::query()
                        ->where('checkout_group_key', $representative->checkoutGroupKey())
                        ->exists();
                    $this->assignments->release($representative, $admin, $request, true);
                    $releasedAssignmentCount += (int) $wasAssigned;
                }
            }

            AdminActivityLogger::log(
                action: 'orders.bulk_action_completed',
                description: 'تم تنفيذ إجراء جماعي على عمليات شراء محددة.',
                subject: $representatives->first(),
                properties: [
                    'action' => $action,
                    'selected_representative_ids' => $ids->all(),
                    'checkout_group_keys' => $representatives->map(fn (Order $order): string => $order->checkoutGroupKey())->all(),
                    'target_status' => $changesStatus ? $status : null,
                    'admin_notes' => $notes,
                    'checkout_count' => $representatives->count(),
                    'status_updated_count' => $statusUpdatedCount,
                    'released_assignment_count' => $releasedAssignmentCount,
                ],
                admin: $admin,
                request: $request,
            );

            return [
                'checkout_count' => $representatives->count(),
                'status_updated_count' => $statusUpdatedCount,
                'released_assignment_count' => $releasedAssignmentCount,
            ];
        }, 3);
    }

    /** @return Collection<int, Order> */
    private function activeOrdersForCheckout(Order $representative): Collection
    {
        $orders = Order::query()
            ->where('checkout_group_key', $representative->checkoutGroupKey())
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'representative_ids' => 'عملية الشراء المحددة لم تعد متاحة.',
            ]);
        }

        return $orders;
    }
}

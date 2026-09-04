<?php

namespace App\Console\Commands;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderStatusService;
use App\Support\OrderStatusRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RepairAgentReadyPreviewStatuses extends Command
{
    protected $signature = 'agent:repair-ready-preview
        {--apply : Apply the repair; without this option the command is a dry run}
        {--agent= : Optionally limit the repair to one Agent admin email}';

    protected $description = 'Move Agent-completed checkouts from preview_uploaded to ready_preview safely';

    public function handle(OrderStatusService $statuses): int
    {
        if (! OrderStatusRegistry::isValid(OrderStatusRegistry::TYPE_ORDER, 'ready_preview')) {
            $this->error('The active ready_preview order status is required before running this repair.');

            return self::FAILURE;
        }

        $agentId = null;
        if (filled($this->option('agent'))) {
            $agent = User::query()->where('email', trim((string) $this->option('agent')))->first();
            if (! $agent) {
                $this->error('The requested Agent account was not found.');

                return self::FAILURE;
            }
            $agentId = $agent->id;
        }

        $completionLogs = AdminActivityLog::query()
            ->where('action', 'agent.checkout_production_completed')
            ->when($agentId, fn ($query) => $query->where('user_id', $agentId))
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'properties'])
            ->filter(fn (AdminActivityLog $log): bool => data_get($log->properties, 'new_status') === 'preview_uploaded')
            ->filter(fn (AdminActivityLog $log): bool => filled(data_get($log->properties, 'checkout_group_key')))
            ->unique(fn (AdminActivityLog $log): string => (string) data_get($log->properties, 'checkout_group_key'));

        $candidates = $this->candidates($completionLogs);
        $orderCount = $candidates->sum(fn (array $candidate): int => $candidate['order_ids']->count());

        $this->info("Eligible Agent checkouts: {$candidates->count()}");
        $this->info("Eligible order records: {$orderCount}");

        if (! $this->option('apply')) {
            $this->warn('Dry run only. Run again with --apply after reviewing these counts.');

            return self::SUCCESS;
        }

        $updatedGroups = 0;
        $updatedOrders = 0;

        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($candidate, $statuses, &$updatedGroups, &$updatedOrders): void {
                $orders = Order::query()
                    ->whereIn('id', $candidate['order_ids'])
                    ->where('status', 'preview_uploaded')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (Order $order): bool => $this->latestStatusWasAgentCompletion($order))
                    ->values();

                if ($orders->isEmpty()) {
                    return;
                }

                $agent = User::query()->find($candidate['agent_user_id']);
                $request = Request::create('/artisan/agent/repair-ready-preview', 'POST');
                $request->setUserResolver(fn (): ?User => $agent);

                $statuses->updateGroup(
                    $orders,
                    'ready_preview',
                    'تصحيح حالة إنتاج سابقة: جاهز لإرسال المعاينة للعميل.',
                    $request,
                );

                $updatedGroups++;
                $updatedOrders += $orders->count();
            }, 3);
        }

        $this->info("Updated Agent checkouts: {$updatedGroups}");
        $this->info("Updated order records: {$updatedOrders}");

        return self::SUCCESS;
    }

    /** @return Collection<int, array{checkout_group_key:string,agent_user_id:int|null,order_ids:Collection<int, int>}> */
    private function candidates(Collection $completionLogs): Collection
    {
        return $completionLogs->map(function (AdminActivityLog $log): ?array {
            $groupKey = (string) data_get($log->properties, 'checkout_group_key');
            $orders = Order::query()
                ->where('checkout_group_key', $groupKey)
                ->where('status', 'preview_uploaded')
                ->get()
                ->filter(fn (Order $order): bool => $this->latestStatusWasAgentCompletion($order));

            if ($orders->isEmpty()) {
                return null;
            }

            return [
                'checkout_group_key' => $groupKey,
                'agent_user_id' => $log->user_id,
                'order_ids' => $orders->pluck('id'),
            ];
        })->filter()->values();
    }

    private function latestStatusWasAgentCompletion(Order $order): bool
    {
        $latest = $order->statusLogs()
            ->where('status_type', 'order')
            ->latest('id')
            ->first(['status', 'notes']);

        return $latest?->status === 'preview_uploaded'
            && $latest->notes === 'اكتمل الإنتاج بواسطة Agent API.';
    }
}

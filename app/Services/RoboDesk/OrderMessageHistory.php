<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Models\OrderProcessStep;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessMessageRetry;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OrderMessageHistory
{
    public static function sendLabel(string $status): string
    {
        return match ($status) {
            'succeeded', 'completed' => 'قبلها RoboDesk',
            'unknown', 'uncertain' => 'نتيجة الإرسال غير مؤكدة',
            'processing' => 'جاري الإرسال — لا تعِد المحاولة',
            'pending', 'retry_pending' => 'في انتظار الإرسال',
            'blocked' => 'توقفت المحاولة — تحتاج مراجعة',
            'held', 'cancelled' => 'الإرسال متوقف',
            default => 'لم يبدأ الإرسال',
        };
    }

    public function stages(Order $order, ?User $actor): Collection
    {
        $stages = collect();
        $events = RoboDeskIntegrationEvent::where('checkout_group_key', $order->checkoutGroupKey())
            ->where('event_type', 'order.pending_confirmation')->where('direction', 'outbound')->orderBy('id')->get();
        if ($events->isNotEmpty()) {
            $last = $events->last();
            $stages->push(['name' => 'تأكيد الطلب', 'source' => 'confirmation', 'source_id' => $last->id,
                'version' => 0, 'retry' => OrderMessageRetry::authorized($actor) && app(OrderMessageRetry::class)->eligible($last),
                'attempts' => $events->map(fn ($e) => $this->row($e->status, $e->response_payload,
                    data_get($e->payload, 'confirmation.send_started_at') ?: $e->created_at,
                    data_get($e->payload, 'manual_retry.requested_by'), max(0, (int) $e->attempts),
                    data_get($e->payload, 'manual_retry.reason'))),
                'count' => $events->sum('attempts')]);
        }
        $steps = OrderProcessStep::where('type', 'whatsapp')->whereHas('run', fn ($q) => $q->where('checkout_key', $order->checkoutGroupKey()))
            ->with(['run.automation', 'run.revision', 'messageAttempts'])->orderBy('id')->get();
        foreach ($steps as $step) {
            $node = collect($step->run->revision->definition['nodes'])->firstWhere('id', $step->node_id);
            $title = ($node['config']['template'] ?? null) === ProcessTemplates::IDENTITY ? 'معاينة الهوية والدفع' : 'تأكيد الطلب';
            $attempts = $step->messageAttempts->sortBy('number')->map(fn ($a) => $this->row($a->status, $a->receipt, $a->started_at ?: $a->created_at, $a->requested_by, $a->started_at ? 1 : 0, $a->reason));
            if ($attempts->isEmpty()) {
                $attempts = collect([$this->row($step->status, $step->receipt, $step->started_at ?: $step->created_at, null, $step->started_at ? 1 : 0)]);
            }
            $stages->push(['name' => $step->run->automation->name.' — '.$title.' ('.$step->node_id.')',
                'source' => 'process', 'source_id' => $step->id, 'version' => $step->run->version,
                'retry' => OrderMessageRetry::authorized($actor) && app(ProcessMessageRetry::class)->eligible($step),
                'attempts' => $attempts, 'count' => $attempts->sum('count')]);
        }
        // One user lookup for all rows; never pass payloads, tokens, wamids or private links to the view.
        $names = User::whereIn('id', $stages->flatMap(fn ($s) => $s['attempts']->pluck('actor_id'))->filter()->unique())->pluck('name', 'id');

        return $stages->map(function ($stage) use ($names) {
            $stage['attempts'] = $stage['attempts']->map(function ($row) use ($names) {
                $row['actor'] = $row['actor_id'] ? ($names[$row['actor_id']] ?? 'موظف سابق') : 'النظام';
                unset($row['actor_id']);

                return $row;
            });

            return $stage;
        });
    }

    private function row(string $status, ?array $receipt, mixed $at, ?int $actorId, int $count, ?string $reason = null): array
    {
        return ['send' => self::sendLabel($status), 'status' => $status,
            'delivery' => TemplateDelivery::label(data_get($receipt, 'delivery.status')),
            'delivery_status' => data_get($receipt, 'delivery.status', 'unknown'),
            'at' => $at ? CarbonImmutable::parse($at)->timezone('Africa/Cairo')->format('Y-m-d H:i:s') : '—',
            'actor_id' => $actorId, 'count' => $count, 'reason' => $reason];
    }
}

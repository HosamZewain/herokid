<?php

namespace App\Services\Agentic;

use App\Models\AdminActivityLog;
use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\AiOrderDecision;
use App\Models\AiProductionExecution;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;

/** Safe, durable order-page events. No free-form model text or customer payloads. */
class AutoPilotAudit
{
    public function decision(AiAgentRun $run, AiOrderDecision $saved): void
    {
        $state = AiCheckoutState::find($run->checkout_state_id);
        $d = $saved->decision;
        $this->record($state?->representative_id, 'decision', 'HeroKid Auto Pilot: '.__('agentic.decisions.'.$d['decision']).' — '.__('agentic.reasons.'.$d['reason_code']),
            ['autopilot_run' => $run->id, 'autopilot_target' => $d['target_id'], 'autopilot_decision' => $d['decision'],
                'autopilot_policy' => $saved->policy['reason_code'], 'autopilot_mode' => 'اقتراح محفوظ؛ التنفيذ له سجل منفصل']);
    }

    public function failed(AiAgentRun $run, string $code): void
    {
        $this->record(AiCheckoutState::find($run->checkout_state_id)?->representative_id, 'decision_failed',
            'HeroKid Auto Pilot: تعذر تأكيد القرار؛ لم يُطلب تنفيذ.', ['autopilot_run' => $run->id, 'autopilot_reason' => $code]);
    }

    public function admission(AiProductionExecution $receipt): void
    {
        $this->record($receipt->order_id, 'admission', $receipt->status === 'admitted'
            ? 'HeroKid Auto Pilot: تسليم الخطوة للمسار الحالي؛ ننتظر نتيجة التنفيذ.' : 'HeroKid Auto Pilot: لم تُسلّم الخطوة للتنفيذ؛ تحتاج مراجعة.',
            ['autopilot_receipt' => $receipt->id, 'autopilot_target' => $receipt->target_id, 'autopilot_action' => $receipt->action,
                'autopilot_reason' => $receipt->reason_code, 'autopilot_executor' => $receipt->executor_type.':'.$receipt->executor_id]);
    }

    public function progress(Model $work, string $type): void
    {
        if (! $work->wasChanged('status') || $work->wasRecentlyCreated) {
            return;
        }
        foreach (AiProductionExecution::where('executor_type', $type)->where('executor_id', (string) $work->getKey())->where('status', 'admitted')->get() as $receipt) {
            $this->record($receipt->order_id, 'progress', 'HeroKid Auto Pilot: تحديث نتيجة المسار المسؤول عن الخطوة.',
                ['autopilot_receipt' => $receipt->id, 'autopilot_action' => $receipt->action,
                    'autopilot_state' => $work->status, 'autopilot_executor' => $type.':'.$work->getKey()]);
        }
    }

    private function record(?int $orderId, string $action, string $description, array $properties): void
    {
        if ($orderId) {
            AdminActivityLog::create(['user_id' => null, 'action' => 'autopilot.'.$action, 'description' => $description,
                'subject_type' => Order::class, 'subject_id' => $orderId, 'properties' => $properties + ['actor_name' => 'HeroKid Auto Pilot']]);
        }
    }
}

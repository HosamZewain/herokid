<?php

namespace App\Services\OrderAutomation\Process;

use App\Jobs\AdvanceOrderProcess;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use App\Support\AdminActivityLogger;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProcessRuntime
{
    public const STATES = ['queued' => 'في انتظار التنفيذ', 'running' => 'قيد التنفيذ', 'sending' => 'جاري إرسال القالب', 'waiting_identity' => 'جاري توليد الهويات', 'waiting_response' => 'ينتظر رد العميل', 'waiting_staff' => 'ينتظر الموظف', 'completed' => 'مكتمل', 'blocked' => 'يحتاج مراجعة', 'uncertain' => 'نتيجة الإرسال غير مؤكدة', 'cancelled' => 'متوقف'];

    public function __construct(private ProcessLivePolicy $policy, private ProcessOrderContext $contexts) {}

    public function activate(OrderAutomation $automation, User $actor, bool $enabled, ?int $revisionId = null): void
    {
        DB::transaction(function () use ($automation, $actor, $enabled, $revisionId) {
            $automation = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_if($revisionId !== null && $revisionId !== (int) $automation->revision_id, 409);
            if ($enabled) {
                abort_unless($this->policy->enabled() && $this->policy->allowsAutomation($automation->id) && $automation->revision_id, 422, 'التنفيذ الفعلي مقفول على الخادم أو لا توجد نسخة محفوظة.');
                $this->policy->check($automation->revision->definition);
                $automation->process_activated_by = $actor->id;
                abort_unless($this->policy->authorized($automation), 403);
                if (! $automation->enabled) {
                    $automation->process_after_event_id = OrderProcessEvent::max('id') ?? 0;
                }
            }
            $automation->enabled = $enabled;
            $automation->save();
            AdminActivityLogger::log('order_process.activation', $enabled ? 'تفعيل مسار للأحداث التالية فقط' : 'إيقاف مسار العمليات', $automation, [], $actor);
        });
    }

    public function tick(): int
    {
        if (! $this->policy->enabled()) {
            return 0;
        }
        $count = 0;
        foreach (OrderProcessEvent::where('status', 'pending')->orderBy('id')->limit(100)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id) {
                $event = OrderProcessEvent::lockForUpdate()->findOrFail($id);
                if ($event->status !== 'pending') {
                    return 0;
                }
                $enrolled = 0;
                foreach (OrderAutomation::where('enabled', true)->whereNotNull('process_after_event_id')->orderBy('id')->get() as $automation) {
                    $automation = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
                    $d = $automation->revision?->definition;
                    if (! $this->policy->allowsAutomation($automation->id) || ! $this->policy->allowsCheckout($event->checkout_key)
                        || ! $automation->enabled || $event->id <= $automation->process_after_event_id || ! $d || ($d['schema'] ?? 1) !== 2 || $d['trigger'] !== $event->type || $d['conditions']['status'] !== ($event->data['status'] ?? null)) {
                        continue;
                    }
                    try {
                        $this->policy->check($d);
                        if (! $this->policy->authorized($automation)) {
                            continue;
                        }
                        $snapshot = $this->contexts->snapshot($this->contexts->orders($event->checkout_key, true));
                        if (! $this->contexts->matches($d, $snapshot)) {
                            continue;
                        }
                    } catch (ValidationException|RuntimeException) {
                        continue;
                    }
                    // Once per workflow/checkout, across revisions and repeated status entry.
                    $key = hash('sha256', $automation->id.':'.$event->checkout_key);
                    $run = OrderProcessRun::firstOrCreate(['enrollment_key' => $key], ['automation_id' => $automation->id, 'revision_id' => $automation->revision_id, 'event_id' => $event->id, 'order_id' => $snapshot['order_ids'][0],
                        'checkout_key' => $event->checkout_key, 'account_key' => config('robodesk.conversations.account_key', 'primary'), 'phone_hash' => hash('sha256', '+'.$snapshot['phone']), 'node_id' => $d['nodes'][0]['id'], 'status' => 'queued', 'context' => $snapshot + ['actor_id' => $automation->process_activated_by], 'next_at' => now()]);
                    $enrolled += (int) $run->wasRecentlyCreated;
                }
                $event->update(['status' => 'processed']);

                return $enrolled;
            });
        }
        foreach (OrderProcessRun::whereIn('status', ['queued', 'running', 'sending', 'waiting_response', 'waiting_identity'])->where(fn ($q) => $q->where('status', 'sending')->orWhereHas('automation', fn ($a) => $a->where('enabled', true)))->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))->orderBy('id')->limit(100)->get() as $run) {
            if ($run->status === 'sending') {
                DB::transaction(function () use ($run) {
                    $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
                    $step = $run->steps()->where('node_id', $run->node_id)->first();
                    if ($run->status === 'sending' && $step?->lease_until?->isPast()) {
                        $step->update(['status' => 'uncertain']);
                        $run->update(['status' => 'uncertain', 'error_code' => 'send_receipt_missing']);
                    }
                });
            } else {
                DB::transaction(function () use ($run) {
                    $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
                    if (! in_array($run->status, ['queued', 'running', 'waiting_response', 'waiting_identity'], true) || ($run->next_at && $run->next_at->isFuture()) || ! $run->automation->enabled) {
                        return;
                    }
                    $run->update(['next_at' => now()->addSeconds(180)]);
                    AdvanceOrderProcess::dispatch($run->id)->afterCommit();
                });
            }
        }

        return $count;
    }

    public function advance(int $id): void
    {
        if (! $this->policy->enabled()) {
            return;
        }
        $send = DB::transaction(function () use ($id) {
            $run = OrderProcessRun::lockForUpdate()->find($id);
            if (! $run || ! in_array($run->status, ['queued', 'running', 'waiting_response', 'waiting_identity'], true) || ! $run->automation->enabled) {
                return null;
            }
            try {
                return DB::transaction(function () use ($run) {
                    $this->policy->check($run->revision->definition);
                    $this->guard($run);
                    // Serialize all workflows for this checkout, including durable customer waits.
                    $active = hash('sha256', $run->checkout_key);
                    if (OrderProcessRun::where('active_key', $active)->whereKeyNot($run->id)->lockForUpdate()->first()) {
                        $run->update(['next_at' => now()->addMinute()]);

                        return null;
                    }
                    $run->active_key = $active;
                    $node = $this->node($run);
                    $step = $run->steps()->firstOrCreate(['node_id' => $node['id']], ['type' => $node['type'], 'status' => 'pending']);
                    if ($run->status === 'waiting_identity') {
                        if (app(ProcessIdentityService::class)->ready($run)) {
                            $step->update(['status' => 'completed', 'completed_at' => now()]);
                            $this->move($run, $node['next']);
                        } else {
                            $run->update(['next_at' => now()->addMinute()]);
                        }

                        return null;
                    }
                    if ($run->status === 'waiting_response') {
                        // Timeout never cancels or assumes a customer decision. Templates may arrive late.
                        if ($run->wait_until?->isPast()) {
                            $step->update(['status' => 'timed_out', 'completed_at' => now()]);
                            $this->move($run, $node['config']['timeout_next']);
                        } else {
                            $run->next_at = now()->addMinutes(10);
                            $run->save();
                        }

                        return null;
                    }
                    if ($step->status !== 'pending') {
                        throw new RuntimeException('step_already_claimed');
                    }
                    $c = $node['config'];
                    switch ($node['type']) {
                        case 'generate_identity':
                            app(ProcessIdentityService::class)->prepare($run, $step, $c);

                            return null;
                        case 'approve_identity':
                            app(ProcessIdentityService::class)->approve($run, $c);
                            break;
                        case 'whatsapp':
                            $identityMessage = $c['template'] === ProcessTemplates::IDENTITY;
                            if ($identityMessage && ! app(ProcessIdentityService::class)->ready($run)) {
                                throw new RuntimeException('identity_requires_review');
                            }
                            if (! $identityMessage && (! $this->policy->ownerReady() || $run->context['status'] !== 'new')) {
                                throw new RuntimeException('confirmation_owner_or_state');
                            }
                            if (! $identityMessage && RoboDeskIntegrationEvent::where('event_type', 'order.pending_confirmation')->where('checkout_group_key', $run->checkout_key)->exists()) {
                                throw new RuntimeException('legacy_confirmation_exists');
                            }
                            if (RoboDeskIntegrationEvent::where('event_type', 'order.pending_confirmation')
                                ->whereHas('order', fn ($q) => $q->where('status', 'new'))
                                ->whereDoesntHave('confirmationWorkflow', fn ($q) => $q->where('confirmation_status', 'confirmed'))
                                ->with('order:id,delivery_details')->get()->contains(fn ($e) => Phone::forWhatsApp(data_get($e->order?->delivery_details, 'phone')) === $run->context['phone'])) {
                                throw new RuntimeException('legacy_confirmation_pending');
                            }
                            $effect = hash('sha256', ($identityMessage ? 'identity-preview:' : 'order-confirmation:').$run->checkout_key);
                            if (OrderProcessStep::where('effect_key', $effect)->exists()) {
                                throw new RuntimeException('confirmation_already_claimed');
                            }
                            $account = config('robodesk.conversations.account_key', 'primary');
                            $lock = Cache::lock('robodesk-history:'.hash('sha256', $account.hash('sha256', '+'.$run->context['phone'])), 130);
                            if (! $lock->get()) {
                                $run->next_at = now()->addSeconds(30);
                                $run->save();

                                return null;
                            }
                            try {
                                $step->update(['status' => 'processing', 'effect_key' => $effect, 'started_at' => now(), 'lease_until' => now()->addSeconds(150)]);
                                $run->status = 'sending';
                                $run->next_at = now()->addSeconds(150);
                                $run->save();

                                return ['run_id' => $run->id, 'step_id' => $step->id, 'lock' => $lock];
                            } catch (Throwable $e) {
                                $lock->release();
                                throw $e;
                            }
                        case 'change_status':
                            if ($run->context['status'] !== $c['from']) {
                                throw new RuntimeException('state_conflict');
                            }
                            // Only policy-approved early-order transitions; no payment/shipping/AI side effects.
                            foreach ($this->contexts->orders($run->checkout_key, true) as $order) {
                                $order->update(['status' => $c['to']]);
                                $order->statusLogs()->create(['status_type' => 'order', 'status' => $c['to'], 'notes' => 'مسار العمليات #'.$run->id]);
                            }
                            $run->context = $this->contexts->snapshot($this->contexts->orders($run->checkout_key, true)) + ['actor_id' => $run->context['actor_id']];
                            break;
                        case 'condition':
                            $next = $c[$run->context[$c['field']] === $c['value'] ? 'yes' : 'no'];
                            $step->update(['status' => 'completed', 'completed_at' => now()]);
                            $this->move($run, $next);

                            return null;
                        case 'wait_response':
                            $sent = $run->steps()->where('node_id', $c['message_node'])->first();
                            if ($sent?->status !== 'completed' || ! data_get($sent->receipt, 'message_id')) {
                                throw new RuntimeException('missing_send_receipt');
                            }
                            $step->update(['status' => 'waiting', 'started_at' => now()]);
                            $run->status = 'waiting_response';
                            $run->wait_until = now()->addHours($c['timeout_hours']);
                            $run->next_at = now()->addMinutes(10);
                            $run->save();

                            return null;
                        case 'manual_review':
                            $step->update(['status' => 'waiting', 'started_at' => now()]);
                            $run->status = 'waiting_staff';
                            $run->save();

                            return null;
                        case 'end':
                            $step->update(['status' => 'completed', 'completed_at' => now()]);
                            $run->status = 'completed';
                            $run->active_key = null;
                            $run->save();

                            return null;
                        default: throw new RuntimeException('unsupported_action');
                    }
                    $step->update(['status' => 'completed', 'started_at' => now(), 'completed_at' => now()]);
                    $this->move($run, $node['next']);

                    return null;
                });
            } catch (ValidationException) {
                $run->refresh()->update(['status' => 'blocked', 'error_code' => 'live_policy_changed']);
            } catch (RuntimeException $exception) {
                $allowed = ['trial_scope_changed', 'order_ineligible', 'contact_invalid', 'actor_revoked', 'inputs_changed', 'step_already_claimed', 'confirmation_owner_or_state', 'legacy_confirmation_exists', 'legacy_confirmation_pending', 'confirmation_already_claimed', 'state_conflict', 'missing_send_receipt', 'unsupported_action', 'identity_disabled', 'identity_scope_incomplete', 'identity_provider_unavailable', 'identity_budget_required', 'identity_already_claimed', 'identity_existing_review', 'identity_requires_review', 'identity_approval_missing', 'missing_inputs', 'invalid_photo', 'photo_too_large'];
                if (! in_array($exception->getMessage(), $allowed, true)) {
                    throw $exception;
                }
                $run->refresh()->update(['status' => 'blocked', 'error_code' => $exception->getMessage()]);
            }

            return null;
        }, 3);
        if ($send) {
            $this->send($send);
        }
    }

    private function send(array $claim): void
    {
        try {
            $run = OrderProcessRun::findOrFail($claim['run_id']);
            $step = OrderProcessStep::findOrFail($claim['step_id']);
            try {
                if (! $this->policy->enabled() || ! $run->automation->enabled) {
                    throw new OrderConfirmationSendException('paused_before_send');
                }
                $this->policy->check($run->revision->definition);
                $this->guard($run);
                $data = app(RoboDeskCheckoutPayload::class)->build($run->checkout_key);
                if ($data['customer']['phone'] !== $run->context['phone']) {
                    throw new OrderConfirmationSendException('contact_changed');
                }
                $template = $this->node($run)['config']['template'];
                if ($template === ProcessTemplates::IDENTITY) {
                    $url = app(ProcessIdentityService::class)->previewUrl($run);
                    $fields = [$data['customer']['name'], $data['display_reference'], implode('، ', $data['stories']), implode('، ', $data['children']),
                        implode('، ', $data['products']) ?: 'لا توجد منتجات إضافية', $url, number_format($data['amounts']['total'], 2, '.', ''), number_format($data['amounts']['remaining'], 2, '.', '')];
                    $receipt = app(OrderConfirmationTransport::class)->sendTemplate($data['customer']['phone'], $template, $fields, 'معاينة هوية طلب HeroKid '.$data['display_reference']);
                } else {
                    $receipt = app(OrderConfirmationTransport::class)->send($data);
                }
                DB::transaction(function () use ($run, $step, $receipt) {
                    $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
                    $step = OrderProcessStep::lockForUpdate()->findOrFail($step->id);
                    // Save a late receipt, but never overwrite cancellation/uncertain review.
                    $step->update(['receipt' => $receipt, 'status' => 'completed', 'completed_at' => now()]);
                    if ($run->status === 'sending') {
                        $this->move($run, $this->node($run)['next']);
                    }
                });
            } catch (Throwable $exception) {
                DB::transaction(function () use ($run, $step, $exception) {
                    $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
                    $status = $exception instanceof OrderConfirmationSendException ? 'blocked' : 'uncertain';
                    $step->update(['status' => $status]);
                    if ($run->status === 'sending') {
                        $run->update(['status' => $status, 'error_code' => $exception instanceof OrderConfirmationSendException ? $exception->reason : 'send_result_uncertain']);
                    }
                });
            }
        } finally {
            $claim['lock']->release();
        }
    }

    public function guard(OrderProcessRun $run): void
    {
        if (! $this->policy->allowsAutomation($run->automation_id) || ! $this->policy->allowsCheckout($run->checkout_key)) {
            throw new RuntimeException('trial_scope_changed');
        }
        if ($run->account_key !== config('robodesk.conversations.account_key', 'primary')) {
            throw new RuntimeException('inputs_changed');
        }
        $authority = clone $run->automation;
        $authority->setRelation('revision', $run->revision);
        $authority->process_activated_by = $run->context['actor_id'];
        if (! $this->policy->authorized($authority)) {
            throw new RuntimeException('actor_revoked');
        }
        $snapshot = $this->contexts->snapshot($this->contexts->orders($run->checkout_key, DB::transactionLevel() > 0));
        if (! hash_equals($run->context['fingerprint'], $snapshot['fingerprint'])) {
            throw new RuntimeException('inputs_changed');
        }
    }

    public function node(OrderProcessRun $run): array
    {
        return collect($run->revision->definition['nodes'])->firstWhere('id', $run->node_id) ?? throw new RuntimeException('unsupported_action');
    }

    public function move(OrderProcessRun $run, string $next): void
    {
        $run->node_id = $next;
        $run->status = 'running';
        $run->version++;
        $run->wait_until = null;
        $run->next_at = now()->addSeconds(180);
        $run->save();
        AdvanceOrderProcess::dispatch($run->id)->afterCommit();
    }

    public function staffAction(OrderProcessRun $run, User $actor, int $version, string $action, string $reason): void
    {
        abort_unless($actor->is_active && $actor->hasPermission('order_automation.execute') && $actor->hasPermission('orders.view') && $actor->hasPermission('orders.update'), 403);
        DB::transaction(function () use ($run, $actor, $version, $action, $reason) {
            $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
            abort_unless($run->version === $version, 409);
            if ($action === 'stop') {
                abort_if($run->identities()->whereIn('status', ['processing', 'uncertain'])->exists(), 422, 'محاولة الهوية الجارية أو غير المؤكدة تحتاج تسوية قبل تحرير الحجز.');
                abort_unless(in_array($run->status, ['queued', 'running', 'waiting_response', 'waiting_staff', 'blocked'], true), 422, 'الإرسال الجاري أو غير المؤكد يحتاج تسوية قبل إنهاء الحجز.');
                $run->update(['status' => 'cancelled', 'active_key' => null, 'version' => $run->version + 1]);
            } else {
                abort_unless($this->policy->enabled() && $run->automation->enabled && $run->status === 'waiting_staff', 422);
                $this->guard($run);
                $run->steps()->where('node_id', $run->node_id)->update(['status' => 'completed', 'completed_at' => now()]);
                $this->move($run, $this->node($run)['next']);
            }
            AdminActivityLogger::log('order_process.staff_action', 'إجراء موظف على مسار العمليات', $run, ['action' => $action, 'reason' => $reason], $actor);
        });
    }
}

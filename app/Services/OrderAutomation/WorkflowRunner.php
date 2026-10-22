<?php

namespace App\Services\OrderAutomation;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WorkflowRunner
{
    public function advance(int $id): void
    {
        $run = DB::transaction(function () use ($id): ?OrderAutomationRun {
            $run = OrderAutomationRun::lockForUpdate()->find($id);
            if (! $run || ! in_array($run->status, ['queued', 'waiting_inputs', 'polling', 'generating'], true) || $run->lease_until?->isFuture()) {
                return null;
            }
            if ($run->status === 'generating') {
                $run->update(['status' => 'uncertain', 'error_code' => 'provider_unknown']);

                return null; // Never submit again after an ambiguous interruption.
            }
            if (! $run->dry_run && ! $run->trial && (! config('order_automation.enabled') || ! $run->automation->enabled)) {
                $run->update(['next_at' => now()->addMinutes(5)]);

                return null;
            }
            $run->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(10)]);

            return $run;
        });
        if (! $run) {
            return;
        }
        try {
            if ($run->trial && ! $run->dry_run) {
                $actor = User::find($run->actor_id);
                if (! $actor || ! $actor->is_active || $actor->role !== 'admin' || ! $actor->hasPermission('order_automation.view') || ! $actor->hasPermission('order_automation.execute') || ! $actor->hasPermission('orders.view') || ! $actor->hasPermission('orders.photos.view')) {
                    throw new RuntimeException('actor_revoked');
                }
            }
            $definition = $run->revision->definition;
            $order = Order::find($run->order_id);
            if (! $order) {
                throw new RuntimeException('order_ineligible');
            }
            $inputs = app(WorkflowInputs::class)->capture($order, $definition);
            if (isset($run->context['fingerprint']) && ! hash_equals($run->context['fingerprint'], $inputs['fingerprint'])) {
                throw new RuntimeException('inputs_changed');
            }
            if ($run->dry_run) {
                $steps = [];
                foreach ($definition['steps'] as $step) {
                    $steps[] = ['type' => $step, 'status' => 'simulated', 'at' => now()->toIso8601String()];
                }
                $this->write($run, ['context' => $inputs, 'steps' => $steps, 'status' => 'simulated', 'error_code' => null]);

                return;
            }
            $step = $definition['steps'][$run->step_index] ?? 'wait';
            switch ($step) {
                case 'prepare':
                    $this->complete($run, $step, $inputs);
                    break;
                case 'generate':
                    $gateway = app(WorkflowImageGateway::class);
                    // Recovery may have waited for inputs/provider availability. An accepted
                    // request is always polled, never submitted a second time.
                    if (isset($run->context['provider_request_id']) && ! isset($run->context['output'])) {
                        $this->write($run, ['status' => 'polling']);
                        if ($run->reserved_at?->lt(now()->subHours(2))) {
                            throw new RuntimeException('provider_timeout');
                        }
                        $output = $gateway->poll($run);
                        if (! $output) {
                            $this->write($run, ['next_at' => now()->addMinute()]);
                            break;
                        }
                        $this->complete($run, $step, array_merge($run->context, ['output' => $output]));
                        break;
                    }
                    $gateway->model($run); // Availability is checked before reserving or sending.
                    $estimate = data_get($definition, 'model_snapshot.configuration_json.quality_costs.'.$definition['quality'])
                        ?? data_get($definition, 'model_snapshot.estimated_cost_amount') ?? data_get($definition, 'model_snapshot.estimated_cost_per_output');
                    if (! is_numeric($estimate) || $estimate <= 0 || $estimate > $definition['max_cost_usd']
                        || (data_get($definition, 'model_snapshot.estimated_cost_currency') ?: 'USD') !== 'USD') {
                        throw new RuntimeException('estimate_exceeds_limit');
                    }
                    $this->reserve($run);
                    $result = $gateway->submit($run);
                    // A late result cannot overwrite cancellation or a newer lease.
                    if (isset($result['output'])) {
                        $this->complete($run, $step, array_merge($run->context, $result));
                    } else {
                        $this->write($run, ['context' => array_merge($run->context, $result), 'status' => 'polling', 'next_at' => now()->addMinute()]);
                    }
                    break;
                case 'save':
                    DB::transaction(function () use ($run, $step): void {
                        $locked = OrderAutomationRun::lockForUpdate()->findOrFail($run->id);
                        $order = Order::lockForUpdate()->findOrFail($run->order_id);
                        $this->guardLease($run, $locked);
                        $current = app(WorkflowInputs::class)->capture($order, $run->revision->definition);
                        if (! hash_equals($run->context['fingerprint'], $current['fingerprint'])) {
                            throw new RuntimeException('inputs_changed');
                        }
                        $output = $run->context['output'];
                        if (! Storage::disk($output['disk'])->exists($output['path']) || ! hash_equals($output['checksum'], hash('sha256', Storage::disk($output['disk'])->get($output['path'])))) {
                            throw new RuntimeException('output_missing');
                        }
                        $attachment = OrderAttachment::create(['order_id' => $run->order_id, 'uploaded_by_user_id' => $run->actor_id,
                            'production_unit_key' => 'story:'.$run->order_id, 'disk' => $output['disk'], 'path' => $output['path'],
                            'original_name' => 'identity-preview-'.$run->id.'.'.$output['extension'], 'mime_type' => $output['mime'], 'size' => $output['bytes'],
                            'note' => 'معاينة هوية مولّدة تلقائيًا — لم تعتمد بعد. تشغيل #'.$run->id]);
                        $this->complete($run, $step, array_merge($run->context, ['attachment_id' => $attachment->id]));
                    });
                    break;
                case 'review':
                    if (! isset($run->context['reviewed_by'])) {
                        $this->write($run, ['status' => 'review']);
                    } else {
                        $this->complete($run, $step, $run->context);
                    }
                    break;
                case 'whatsapp':
                    if ($run->trial) {
                        throw new RuntimeException('trial_delivery_prohibited');
                    }
                    // Fail closed until the actual RoboDesk template contract is supplied.
                    throw new RuntimeException('delivery_not_configured');
                case 'wait':
                    $this->complete($run, $step, $run->context, isset($run->context['delivery_id']) ? 'awaiting_customer' : 'preview_ready');
                    break;
            }
        } catch (Throwable $exception) {
            $fresh = $run->fresh();
            $code = in_array($exception->getMessage(), ['missing_inputs', 'inputs_changed', 'order_ineligible', 'budget_limit', 'concurrency_limit', 'provider_unavailable', 'provider_failed', 'provider_timeout', 'invalid_photo', 'photo_too_large', 'invalid_output', 'invalid_output_url', 'output_missing', 'delivery_not_configured', 'trial_delivery_prohibited', 'lease_lost', 'estimate_exceeds_limit', 'actor_revoked', 'automation_disabled'], true) ? $exception->getMessage() : 'step_failed';
            if ($code !== 'lease_lost' && $fresh?->lease_token === $run->lease_token && ! in_array($fresh->status, ['cancelled', 'uncertain'], true)) {
                $status = $fresh->status === 'generating' ? 'uncertain' : ($code === 'missing_inputs' || $code === 'concurrency_limit' ? 'waiting_inputs' : 'blocked');
                $this->write($run, ['status' => $status, 'error_code' => $fresh->status === 'generating' ? 'provider_unknown' : $code, 'next_at' => now()->addMinutes(5)]);
            }
        } finally {
            OrderAutomationRun::whereKey($run->id)->where('lease_token', $run->lease_token)->update(['lease_token' => null, 'lease_until' => null]);
        }
    }

    private function reserve(OrderAutomationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $workflow = OrderAutomation::lockForUpdate()->findOrFail($run->automation_id);
            $locked = OrderAutomationRun::lockForUpdate()->findOrFail($run->id);
            $this->guardLease($run, $locked);
            if (! $run->trial && (! $workflow->enabled || ! config('order_automation.enabled'))) {
                throw new RuntimeException('automation_disabled');
            }
            if ($locked->reserved_microusd > 0) {
                throw new RuntimeException('provider_unknown');
            }
            $def = $run->revision->definition;
            $amount = (int) round($def['max_cost_usd'] * 1000000);
            $spent = OrderAutomationRun::where('automation_id', $workflow->id)->where('reserved_at', '>=', now()->startOfDay())->sum('reserved_microusd');
            if ($spent + $amount > round($def['daily_budget_usd'] * 1000000)) {
                throw new RuntimeException('budget_limit');
            }
            if (OrderAutomationRun::where('automation_id', $workflow->id)->whereIn('status', ['generating', 'polling', 'uncertain'])->count() >= $def['max_concurrent']) {
                throw new RuntimeException('concurrency_limit');
            }
            $locked->update(['reserved_microusd' => $amount, 'reserved_at' => now(), 'status' => 'generating']);
            $run->status = 'generating';
        });
    }

    private function complete(OrderAutomationRun $run, string $step, array $context, string $status = 'queued'): void
    {
        $steps = $run->steps ?? [];
        $steps[] = ['type' => $step, 'status' => 'completed', 'at' => now()->toIso8601String()];
        $this->write($run, ['steps' => $steps, 'context' => $context, 'step_index' => $run->step_index + 1, 'status' => $status, 'error_code' => null, 'next_at' => now()]);
    }

    private function write(OrderAutomationRun $run, array $attributes): void
    {
        DB::transaction(function () use ($run, $attributes): void {
            $locked = OrderAutomationRun::lockForUpdate()->findOrFail($run->id);
            $this->guardLease($run, $locked);
            $locked->update($attributes);
        });
    }

    private function guardLease(OrderAutomationRun $run, OrderAutomationRun $locked): void
    {
        if ($locked->lease_token !== $run->lease_token || $locked->status === 'cancelled') {
            throw new RuntimeException('lease_lost');
        }
    }
}

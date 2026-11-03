<?php

namespace App\Providers;

use App\Jobs\EvaluateAgenticShadow;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderItem;
use App\Models\OrderItemProductionComponent;
use App\Models\OrderPaymentEvent;
use App\Models\OrderPaymentReview;
use App\Models\OrderPreview;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\Providers\CodexSdkProvider;
use App\Services\Agentic\Providers\DecisionProvider;
use App\Services\Agentic\Providers\MockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class AgenticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DecisionProvider::class, fn () => match (config('agentic.provider')) {
            'codex' => new CodexSdkProvider, 'mock' => new MockProvider,
            default => throw new \RuntimeException('unknown_decision_provider'),
        });
    }

    public function boot(): void
    {
        Schedule::command('agentic:observe')->everyMinute()->withoutOverlapping(5)->when(fn () => config('agentic.observe'));
        Schedule::call(fn () => EvaluateAgenticShadow::dispatch())->everyMinute()->name('agentic-shadow-dispatch')
            ->when(fn () => config('agentic.observe') && config('agentic.evaluate'));
        foreach ([Order::class, OrderItem::class, OrderItemProductionComponent::class, OrderAttachment::class, OrderPreview::class,
            OrderPaymentEvent::class, OrderPaymentReview::class, OrderProcessRun::class, OrderProcessIdentity::class,
            OrderReviewManifest::class, PuzzleGeneration::class, CodexSceneRun::class, ChildIdentityGenerationAttempt::class] as $class) {
            $listener = function (Model $model): void {
                if (! config('agentic.observe')) {
                    return;
                }
                // Capture identifiers before Eloquent synchronizes the original attributes.
                $oldKey = $model instanceof Order ? $model->getOriginal('checkout_group_key') : null;
                $id = $model->getKey();
                try {
                    DB::afterCommit(function () use ($model, $id, $oldKey): void {
                        try {
                            $keys = [];
                            if ($model instanceof Order) {
                                $keys = [$model->checkoutGroupKey(), $oldKey];
                            } elseif ($model instanceof ChildIdentityGenerationAttempt) {
                                $keys = Order::where('child_identity_request_id', $model->child_identity_request_id)
                                    ->orWhere('child_identity_approved_attempt_id', $id)->pluck('checkout_group_key')->all();
                            } elseif ($model instanceof OrderItemProductionComponent) {
                                $key = OrderItem::find($model->order_item_id)?->order?->checkoutGroupKey();
                                $keys = [$key];
                            } elseif ($model->getAttribute('checkout_key') || $model->getAttribute('checkout_group_key')) {
                                $keys = [$model->getAttribute('checkout_key') ?: $model->getAttribute('checkout_group_key')];
                            } elseif ($model->getAttribute('order_id')) {
                                $keys = [Order::withTrashed()->find($model->order_id)?->checkoutGroupKey()];
                            }
                            foreach (array_unique(array_filter($keys)) as $key) {
                                app(ContextObserver::class)->markDirty($key);
                            }
                        } catch (\Throwable) {
                            Log::warning('agentic_observation_hint_failed');
                        }
                    });
                } catch (\Throwable) {
                    // Observation must never fail the source transaction.
                    Log::warning('agentic_observation_hint_unavailable');
                }
            };
            $class::saved($listener);
            $class::deleted($listener);
        }
    }
}

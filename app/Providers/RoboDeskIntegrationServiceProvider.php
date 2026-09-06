<?php

namespace App\Providers;

use App\Http\Middleware\VerifyRoboDeskSignature;
use App\Models\BookletPreview;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\Order;
use App\Models\OrderProductPreviewGallery;
use App\Services\RoboDesk\Actions\ConfirmItemAction;
use App\Services\RoboDesk\RoboDeskActionRegistry;
use App\Services\RoboDesk\RoboDeskDispatcher;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Support\OrderWorkflowStatus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The single place every RoboDesk trigger lives.
 *
 * Triggers only detect a change and hand off to RoboDeskDispatcher, which owns
 * the "is this action enabled" and "what does its payload look like" decisions.
 * Each one is guarded by a table check so migrations can run on a fresh
 * database, and every dispatch is deduplicated inside the outbox.
 */
class RoboDeskIntegrationServiceProvider extends ServiceProvider
{
    private ?bool $ready = null;

    public function boot(): void
    {
        Route::middleware(['api', VerifyRoboDeskSignature::class, 'throttle:60,1'])
            ->prefix('api/integrations/robodesk/v1')
            ->as('api.robodesk.')
            ->group(base_path('routes/robodesk-api.php'));

        Route::middleware(['web', 'auth', 'is_admin', 'admin_audit'])
            ->prefix('admin/robodesk')
            ->as('admin.robodesk.')
            ->group(base_path('routes/robodesk-admin.php'));

        $this->registerOrderTriggers();
        $this->registerIdentityTriggers();
        $this->registerItemTriggers();
    }

    private function registerOrderTriggers(): void
    {
        Order::created(function (Order $order): void {
            if (! $this->ready()) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmOrder($order);
        });

        Order::updated(function (Order $order): void {
            if (! $this->ready() || ! $order->wasChanged(['status', 'payment_status', 'printing_status', 'shipping_status'])) {
                return;
            }

            $fingerprint = collect(['status', 'payment_status', 'printing_status', 'shipping_status'])
                ->map(fn (string $field): string => $field.'='.(string) $order->{$field})
                ->implode('|');

            app(RoboDeskOutbox::class)->queue(
                'order.workflow_updated',
                'order.workflow_updated:'.$order->id.':'.sha1($fingerprint),
                $order->checkoutGroupKey(),
                $order->id,
                ['changed_order_id' => $order->id],
            );

            // CSAT fires once the courier reports delivery. The action's own
            // delay param decides how long after that the survey is sent.
            if ($order->wasChanged('shipping_status')
                && $order->shipping_status === OrderWorkflowStatus::SHIPPING_DELIVERED) {
                app(RoboDeskDispatcher::class)->requestCsat($order);
            }
        });
    }

    private function registerIdentityTriggers(): void
    {
        ChildIdentityGenerationAttempt::updated(function (ChildIdentityGenerationAttempt $attempt): void {
            if (! $this->ready() || ! $attempt->wasChanged('status') || $attempt->status !== 'succeeded') {
                return;
            }

            $identity = $attempt->identityRequest;

            // Only an identity still awaiting a decision is sent for review. An
            // auto-approved one (the gate is off) is already settled.
            if (! $identity || $identity->approved_attempt_id === $attempt->id) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmIdentity($identity, $attempt);
        });
    }

    private function registerItemTriggers(): void
    {
        BookletPreview::updated(function (BookletPreview $preview): void {
            if (! $this->ready() || ! $preview->order_id || ! $preview->wasChanged('current_version_id')) {
                return;
            }

            $order = $preview->order;

            if (! $order) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmItem($order, [
                'item_title' => $order->story?->title ?? $order->order_number,
                'item_type' => 'story',
                'preview_url' => $preview->publicUrl(),
                'preview_version' => (string) $preview->current_version_id,
            ]);
        });

        OrderProductPreviewGallery::created(function (OrderProductPreviewGallery $gallery): void {
            if (! $this->ready()) {
                return;
            }

            $action = app(RoboDeskActionRegistry::class)->find(ConfirmItemAction::KEY);

            if (! $action || ! filter_var($action->param('include_product_previews', '1'), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            // A product gallery belongs to a checkout group rather than a
            // single order, so the first order in the group represents it.
            $order = Order::query()
                ->where('checkout_group_key', $gallery->checkout_group_key)
                ->orderBy('id')
                ->first();

            if (! $order) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmItem($order, [
                'item_title' => $order->order_number,
                'item_type' => 'product',
                'preview_url' => $gallery->publicUrl(),
                'preview_version' => 'gallery-'.$gallery->id,
            ]);
        });
    }

    /**
     * Migrations and a fresh install must not try to write integration rows
     * before the tables exist. Memoised because this runs on every order,
     * identity and preview write — two schema round-trips per model event on a
     * shared database adds up quickly.
     */
    private function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('robodesk_integration_events')
            && Schema::hasTable('robodesk_action_settings');
    }
}

<?php

namespace App\Providers;

use App\Http\Middleware\VerifyRoboDeskSignature;
use App\Models\BookletPreview;
use App\Models\Order;
use App\Services\RoboDesk\RoboDeskOutbox;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class RoboDeskIntegrationServiceProvider extends ServiceProvider
{
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

        Order::created(function (Order $order): void {
            if (! Schema::hasTable('robodesk_integration_events')) {
                return;
            }

            app(RoboDeskOutbox::class)->queue(
                'order.pending_confirmation',
                'order.pending_confirmation:'.$order->checkoutGroupKey(),
                $order->checkoutGroupKey(),
                $order->id,
            );
        });

        Order::updated(function (Order $order): void {
            if (! Schema::hasTable('robodesk_integration_events') || ! $order->wasChanged(['status', 'payment_status', 'printing_status', 'shipping_status'])) {
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
        });

        BookletPreview::updated(function (BookletPreview $preview): void {
            if (! $preview->order_id || ! $preview->wasChanged('current_version_id') || ! Schema::hasTable('robodesk_integration_events')) {
                return;
            }

            $order = $preview->order;
            if (! $order) {
                return;
            }

            app(RoboDeskOutbox::class)->queue(
                'preview.ready_for_review',
                'preview.ready_for_review:'.$preview->id.':'.$preview->current_version_id,
                $order->checkoutGroupKey(),
                $order->id,
                [
                    'order_number' => $order->order_number,
                    'preview_version_id' => $preview->current_version_id,
                    'preview_url' => $preview->publicUrl(),
                ],
            );
        });
    }
}

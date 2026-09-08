<?php

namespace App\Providers;

use App\Http\Middleware\VerifyRoboDeskSignature;
use App\Models\Order;
use App\Services\RoboDesk\RoboDeskDispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The single place every RoboDesk trigger lives.
 *
 * A trigger only detects a change and hands off to RoboDeskDispatcher, which
 * decides whether the integration is on and what its payload looks like.
 *
 * Today there is one: a new order calls the Order Confirmation integration.
 * The next ones — identity confirmation, item confirmation, CSAT — each add a
 * config entry and one closure here.
 */
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

        // Trigger: order created → Order Confirmation integration.
        Order::created(function (Order $order): void {
            if (! $this->ready()) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmOrder($order);
        });
    }

    /**
     * Migrations and a fresh install must not try to write integration rows
     * before the tables exist.
     */
    private function ready(): bool
    {
        return Schema::hasTable('robodesk_integration_events')
            && Schema::hasTable('robodesk_integration_settings');
    }
}

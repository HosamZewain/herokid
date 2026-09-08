<?php

namespace App\Providers;

use App\Http\Middleware\VerifyRoboDeskSignature;
use App\Models\ChildIdentityGenerationAttempt;
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
 * Today there are two: a new order calls Order Confirmation, and a generated
 * child identity awaiting a decision calls Identity Confirmation. The next ones
 * — item confirmation, CSAT — each add a config entry and one closure here.
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

        // Trigger: identity generated and still undecided → Identity Confirmation.
        ChildIdentityGenerationAttempt::updated(function (ChildIdentityGenerationAttempt $attempt): void {
            if (! $this->ready() || ! $attempt->wasChanged('status') || $attempt->status !== 'succeeded') {
                return;
            }

            $identity = $attempt->identityRequest;

            // An attempt that approved itself was auto-approved, which means the
            // identity gate is closed and there is nothing to ask the parent.
            if (! $identity || $identity->approved_attempt_id === $attempt->id) {
                return;
            }

            app(RoboDeskDispatcher::class)->confirmIdentity($identity, $attempt);
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

<?php

use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\RequireAdminPermission;
use App\Http\Middleware\SecurityAndCacheHeaders;
use App\Http\Middleware\TrackCustomerVisit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['_fbp', '_fbc']);

        $middleware->web(
            prepend: [
                SecurityAndCacheHeaders::class,
                CanonicalHost::class,
            ],
            append: [
                TrackCustomerVisit::class,
            ],
        );

        $middleware->alias([
            'is_admin' => IsAdmin::class,
            'admin_audit' => LogAdminActivity::class,
            'permission' => RequireAdminPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

<?php

use App\Exceptions\AgentApiException;
use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\EnsureAgentApiAccess;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\RequireAdminPermission;
use App\Http\Middleware\SecurityAndCacheHeaders;
use App\Http\Middleware\TrackCustomerVisit;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
            'agent_api' => EnsureAgentApiAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json([
                    'success' => false,
                    'error' => 'UNAUTHORIZED',
                    'message' => 'Valid Agent API credentials are required.',
                ], 401);
            }
        });

        $exceptions->render(function (AgentApiException $exception, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json($exception->payload(), $exception->httpStatus);
            }
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json([
                    'success' => false,
                    'error' => 'ORDER_NOT_FOUND',
                    'message' => 'The requested order resource was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json([
                    'success' => false,
                    'error' => 'ORDER_NOT_FOUND',
                    'message' => 'The requested Agent API resource was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/agent/*')) {
                return response()->json([
                    'success' => false,
                    'error' => 'RATE_LIMITED',
                    'message' => 'Too many Agent API requests. Retry later.',
                ], 429, $exception->getHeaders());
            }
        });
    })->create();

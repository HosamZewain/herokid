<?php

namespace App\Http\Middleware;

use App\Support\AdminActivityLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminActivity
{
    private const SKIPPED_ROUTES = [
        'admin.activity-logs.index',
        'admin.activity-logs.show',
        'admin.orders.photo',
        'admin.attachments.download',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $request->user()?->role === 'admin'
            && ! AdminActivityLogger::requestWasLogged($request)
            && ! $this->shouldSkip($request)
        ) {
            $subject = $this->subjectFromRoute($request);

            AdminActivityLogger::log(
                action: $this->actionName($request),
                description: $this->description($request, $subject),
                subject: $subject,
                properties: [
                    'route_parameters' => $this->routeParameters($request),
                    'request_payload' => $request->except(['_token', '_method']),
                    'response_status' => $response->getStatusCode(),
                ],
                request: $request,
                markRequestLogged: true,
            );
        }

        return $response;
    }

    private function shouldSkip(Request $request): bool
    {
        $routeName = (string) $request->route()?->getName();

        return in_array($routeName, self::SKIPPED_ROUTES, true);
    }

    private function subjectFromRoute(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    private function routeParameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->map(fn (mixed $value): mixed => $value instanceof Model ? [
                'type' => $value::class,
                'id' => $value->getKey(),
                'label' => $value->getAttribute('title') ?? $value->getAttribute('name') ?? $value->getKey(),
            ] : $value)
            ->all();
    }

    private function actionName(Request $request): string
    {
        $routeName = (string) $request->route()?->getName();

        if ($request->isMethod('GET')) {
            return match ($routeName) {
                'admin.dashboard.index' => 'admin.dashboard.viewed',
                default => 'admin.page.viewed',
            };
        }

        return match ($request->method()) {
            'POST' => 'admin.created',
            'PUT', 'PATCH' => 'admin.updated',
            'DELETE' => 'admin.deleted',
            default => 'admin.action',
        };
    }

    private function description(Request $request, ?Model $subject): string
    {
        $routeName = (string) $request->route()?->getName();

        if ($subject) {
            $label = $subject->getAttribute('title')
                ?? $subject->getAttribute('name')
                ?? $subject->getAttribute('order_number')
                ?? ('#' . $subject->getKey());

            return "تنفيذ {$request->method()} على {$routeName}: {$label}";
        }

        return $request->isMethod('GET')
            ? "عرض صفحة إدارية: {$routeName}"
            : "تنفيذ إجراء إداري: {$routeName}";
    }
}

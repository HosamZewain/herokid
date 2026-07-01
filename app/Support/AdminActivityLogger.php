<?php

namespace App\Support;

use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AdminActivityLogger
{
    private const LOGGED_ATTRIBUTE = 'admin_activity_logged';

    private const SENSITIVE_KEYS = [
        '_token',
        '_method',
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'api_key',
        'secret',
        'authorization',
    ];

    public static function log(
        string $action,
        ?string $description = null,
        ?Model $subject = null,
        array $properties = [],
        ?User $admin = null,
        ?Request $request = null,
        bool $markRequestLogged = true,
    ): ?AdminActivityLog {
        $request ??= request();
        $admin ??= $request?->user();

        if (! $admin || $admin->role !== 'admin') {
            return null;
        }

        if ($markRequestLogged && $request) {
            $request->attributes->set(self::LOGGED_ATTRIBUTE, true);
        }

        return AdminActivityLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'route_name' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'url' => $request?->fullUrl(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'properties' => self::sanitize($properties),
        ]);
    }

    public static function requestWasLogged(Request $request): bool
    {
        return (bool) $request->attributes->get(self::LOGGED_ATTRIBUTE, false);
    }

    public static function sanitize(array $payload): array
    {
        return self::sanitizeValue($payload);
    }

    private static function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[filtered]';
        }

        if ($value instanceof UploadedFile) {
            return [
                'file_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
            ];
        }

        if (is_array($value)) {
            return collect($value)
                ->mapWithKeys(fn (mixed $item, string|int $itemKey): array => [
                    $itemKey => self::sanitizeValue($item, (string) $itemKey),
                ])
                ->all();
        }

        if ($value instanceof Model) {
            return [
                'type' => $value::class,
                'id' => $value->getKey(),
            ];
        }

        if (is_string($value)) {
            return Str::limit($value, 2000, '...');
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || Str::contains($normalized, ['password', 'token', 'secret', 'authorization']);
    }

    public static function changedValues(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $newValue) {
            $oldValue = Arr::get($before, $key);

            if ($oldValue != $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return self::sanitize($changes);
    }
}

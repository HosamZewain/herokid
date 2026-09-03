<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\AgentApiIdempotencyKey;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgentIdempotencyService
{
    /**
     * @param  callable(): array{status: int, body: array, checkout_group_key?: string|null, order_id?: int|null}  $operation
     * @return array{status: int, body: array}
     */
    public function execute(User $user, string $action, Request $request, callable $operation): array
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || mb_strlen($key) > 200) {
            throw new AgentApiException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key header is required.', 422);
        }

        $keyHash = hash('sha256', $key);
        $fingerprint = $this->fingerprint($request);

        try {
            $record = AgentApiIdempotencyKey::query()->create([
                'user_id' => $user->id,
                'action' => $action,
                'key_hash' => $keyHash,
                'request_fingerprint' => $fingerprint,
                'status' => 'processing',
            ]);
        } catch (UniqueConstraintViolationException) {
            $record = AgentApiIdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('key_hash', $keyHash)
                ->firstOrFail();

            if (! hash_equals($record->request_fingerprint, $fingerprint)) {
                throw new AgentApiException('IDEMPOTENCY_KEY_REUSED', 'The Idempotency-Key was already used for a different request.', 409);
            }

            if ($record->status === 'completed') {
                return ['status' => $record->response_status ?? 200, 'body' => $record->response_body ?? []];
            }

            throw new AgentApiException('REQUEST_IN_PROGRESS', 'A request with this Idempotency-Key is still in progress.', 409);
        }

        try {
            $result = $operation();
            DB::transaction(function () use ($record, $result): void {
                $record->update([
                    'status' => 'completed',
                    'response_status' => $result['status'],
                    'response_body' => $result['body'],
                    'checkout_group_key' => $result['checkout_group_key'] ?? null,
                    'order_id' => $result['order_id'] ?? null,
                ]);
            });

            return Arr::only($result, ['status', 'body']);
        } catch (Throwable $exception) {
            $record->delete();
            throw $exception;
        }
    }

    private function fingerprint(Request $request): string
    {
        $payload = $request->except(['attachments', 'preview_files']);
        ksort($payload);

        $files = collect($request->allFiles())->flatten()->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->map(fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ])->values()->all();

        return hash('sha256', json_encode([
            'method' => $request->method(),
            'path' => $request->path(),
            'payload' => $payload,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

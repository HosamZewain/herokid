<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class OrderAttachmentService
{
    public const VALIDITY_DAYS = 30;

    /** @param array<int, UploadedFile> $files */
    public function upload(
        Order $order,
        array $files,
        ?string $note,
        ?User $actor,
        ?Request $request = null,
        ?string $productionUnitKey = null,
    ): Collection {
        $created = collect();
        $storedPaths = collect();

        try {
            foreach ($files as $file) {
                $path = $file->store("order-attachments/{$order->id}", 'local');
                abort_unless($path, 422, 'تعذر حفظ ملف الإنتاج في التخزين الخاص.');
                $storedPaths->push($path);

                $created->push($order->attachments()->create([
                    'uploaded_by_user_id' => $actor?->id,
                    'production_unit_key' => $productionUnitKey,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'note' => $note,
                    'validity_days' => self::VALIDITY_DAYS,
                    'expires_at' => now()->addDays(self::VALIDITY_DAYS),
                ]));
            }
        } catch (\Throwable $exception) {
            $created->each->delete();
            $storedPaths->each(fn (string $path): bool => Storage::disk('local')->delete($path));
            throw $exception;
        }

        $isAgentApi = $request?->is('api/agent/*') ?? false;
        $idempotencyKey = trim((string) $request?->header('Idempotency-Key'));

        AdminActivityLogger::log(
            action: $isAgentApi ? 'agent.order_attachments_uploaded' : 'order.attachments_uploaded',
            description: $isAgentApi
                ? 'رفع Agent API مرفقات إنتاج خاصة للطلب '.$order->order_number
                : 'تم رفع مرفقات خاصة للطلب '.$order->order_number,
            subject: $order,
            properties: [
                'attachment_ids' => $created->pluck('id')->all(),
                'file_names' => $created->pluck('original_name')->all(),
                'validity_days' => self::VALIDITY_DAYS,
                'production_unit_key' => $productionUnitKey,
                'expires_at' => $created->first()?->expires_at?->toIso8601String(),
                'request_identifier' => $idempotencyKey === '' ? null : hash('sha256', $idempotencyKey),
            ],
            admin: $actor,
            request: $request,
        );

        return $created;
    }

    public function response(OrderAttachment $attachment, string $disposition = 'inline')
    {
        abort_if($attachment->isExpired(), 410, 'انتهت صلاحية المرفق.');
        $disk = Storage::disk($attachment->disk ?: 'local');
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    /**
     * Permanently delete expired private files and their metadata.
     *
     * @return array{expired: int, deleted_files: int}
     */
    public function cleanupExpired(int $limit = 100): array
    {
        $attachments = OrderAttachment::query()
            ->where('expires_at', '<=', now())
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get();

        $deletedFiles = 0;

        foreach ($attachments as $attachment) {
            $disk = Storage::disk($attachment->disk ?: 'local');

            if ($disk->exists($attachment->path)) {
                $deletedFiles++;
            }

            $attachment->delete();
        }

        return [
            'expired' => $attachments->count(),
            'deleted_files' => $deletedFiles,
        ];
    }
}

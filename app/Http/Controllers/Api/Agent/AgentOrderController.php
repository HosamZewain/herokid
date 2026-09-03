<?php

namespace App\Http\Controllers\Api\Agent;

use App\Exceptions\AgentApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Services\AgentApi\AgentCheckoutProductionService;
use App\Services\AgentApi\AgentIdempotencyService;
use App\Services\BookletPreviews\BookletPreviewManager;
use App\Services\Orders\OrderAttachmentService;
use App\Services\Orders\OrderProductPreviewService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgentOrderController extends Controller
{
    public function attachments(
        Request $request,
        Order $order,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
        OrderAttachmentService $attachments,
    ): JsonResponse {
        $production->authorizedOrder($order, $request->user());
        $validated = $this->validate($request, [
            'attachments' => ['required', 'array', 'min:1', 'max:10'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'note' => ['nullable', 'string', 'max:1000'],
            'production_unit_key' => ['nullable', 'string', 'max:120'],
        ], 'INVALID_ATTACHMENT');
        $unitKey = $production->validateUnitForOrder($order, $validated['production_unit_key'] ?? null);

        $result = $idempotency->execute($request->user(), 'orders.attachments:'.$order->id, $request, function () use ($request, $order, $attachments, $validated, $unitKey): array {
            $created = $attachments->upload(
                $order,
                $request->file('attachments', []),
                $validated['note'] ?? null,
                $request->user(),
                $request,
                $unitKey,
            );

            return [
                'status' => 201,
                'body' => [
                    'success' => true,
                    'attachments' => $created->map(fn (OrderAttachment $attachment): array => [
                        'id' => $attachment->id,
                        'name' => $attachment->original_name,
                        'production_unit_key' => $attachment->production_unit_key,
                    ])->all(),
                ],
                'order_id' => $order->id,
                'checkout_group_key' => $order->checkoutGroupKey(),
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function previews(
        Request $request,
        Order $order,
        AgentCheckoutProductionService $production,
        AgentIdempotencyService $idempotency,
        BookletPreviewManager $booklets,
        OrderProductPreviewService $productPreviews,
    ): JsonResponse {
        $production->authorizedOrder($order, $request->user());
        $type = (string) $request->input('type');
        $rules = match ($type) {
            'booklet' => ['type' => ['required', 'in:booklet'], 'preview_files' => ['required', 'array', 'size:1'], 'preview_files.*' => ['required', 'file', 'mimes:pdf', 'max:51200'], 'note' => ['nullable', 'string', 'max:1000']],
            'product_images' => ['type' => ['required', 'in:product_images'], 'preview_files' => ['required', 'array', 'min:1', 'max:10'], 'preview_files.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'], 'note' => ['nullable', 'string', 'max:1000']],
            default => throw new AgentApiException('INVALID_ATTACHMENT', 'Preview type must be booklet or product_images.', 422),
        };
        $validated = $this->validate($request, $rules, 'INVALID_ATTACHMENT');

        $production->assertPreviewTypeForOrder($order, $type);

        $result = $idempotency->execute($request->user(), 'orders.previews:'.$order->id.':'.$type, $request, function () use ($request, $order, $type, $validated, $booklets, $productPreviews): array {
            try {
                if ($type === 'booklet') {
                    $preview = $booklets->createOrReplaceForOrder($order, $request->file('preview_files')[0], $validated['note'] ?? null, $request->user());
                    $payload = ['type' => 'booklet', 'preview_id' => $preview->id, 'version' => $preview->currentVersion?->version_number];
                } else {
                    $gallery = $productPreviews->upload($order, $request->file('preview_files', []), $validated['note'] ?? null, $request->user());
                    $payload = ['type' => 'product_images', 'gallery_id' => $gallery->id, 'images_count' => $gallery->previews->count()];
                }
            } catch (ValidationException $exception) {
                throw new AgentApiException('INVALID_ATTACHMENT', 'The preview file is invalid.', 422, [
                    'validation' => $exception->errors(),
                ]);
            }

            AdminActivityLogger::log(
                action: 'agent.order_preview_uploaded',
                description: 'رفع Agent API معاينة إنتاج للطلب.',
                subject: $order,
                properties: $payload + [
                    'agent_user_id' => $request->user()->id,
                    'request_identifier' => hash('sha256', (string) $request->header('Idempotency-Key')),
                ],
                admin: $request->user(),
                request: $request,
            );

            return ['status' => 201, 'body' => ['success' => true, 'preview' => $payload], 'order_id' => $order->id, 'checkout_group_key' => $order->checkoutGroupKey()];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function childPhoto(Request $request, Order $order, int $index, AgentCheckoutProductionService $production)
    {
        $production->authorizedOrder($order, $request->user());
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));
        $path = $photos[$index] ?? null;
        if (! $path || str_contains($path, '..')) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Reference file not found.', 404);
        }

        return $this->privateFile($path, 'child-reference-'.$order->id.'-'.($index + 1));
    }

    public function approvedIdentity(Request $request, Order $order, AgentCheckoutProductionService $production)
    {
        $production->authorizedOrder($order, $request->user());
        $attempt = $order->childIdentityApprovedAttempt;
        if (! $attempt || $attempt->status !== 'succeeded' || blank($attempt->output_storage_path)) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Approved identity not found.', 404);
        }

        return $this->privateFile($attempt->output_storage_path, 'approved-identity-'.$order->id, $attempt->output_disk ?: 'local');
    }

    public function attachment(Request $request, Order $order, OrderAttachment $attachment, AgentCheckoutProductionService $production, OrderAttachmentService $attachments)
    {
        $production->authorizedOrder($order, $request->user());
        if ($attachment->order_id !== $order->id) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Attachment not found.', 404);
        }

        if ($attachment->isExpired()) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Attachment has expired.', 410);
        }

        if (! Storage::disk($attachment->disk ?: 'local')->exists($attachment->path)) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Attachment not found.', 404);
        }

        return $attachments->response($attachment, 'inline');
    }

    private function validate(Request $request, array $rules, string $code): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new AgentApiException($code, 'The request contains invalid files or fields.', 422, ['validation' => $validator->errors()->toArray()]);
        }

        return $validator->validated();
    }

    private function privateFile(string $path, string $name, string $disk = 'local')
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            foreach (['local', 'public'] as $candidate) {
                if (Storage::disk($candidate)->exists($path)) {
                    $storage = Storage::disk($candidate);
                    break;
                }
            }
        }
        if (! $storage->exists($path)) {
            $legacyPath = storage_path('app/'.ltrim($path, '/'));
            if (is_file($legacyPath)) {
                return response()->file($legacyPath, [
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        if (! $storage->exists($path)) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Reference file not found.', 404);
        }

        return $storage->response($path, $name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}

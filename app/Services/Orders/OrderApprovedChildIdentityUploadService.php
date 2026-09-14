<?php

namespace App\Services\Orders;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OrderApprovedChildIdentityUploadService
{
    public function __construct(
        private readonly ChildIdentityApprovalService $approvals,
        private readonly OrderChildIdentityPromptService $identityPrompts,
    ) {}

    public function upload(Order $order, UploadedFile $file, User $admin, string $source = 'admin_manual_upload'): ChildIdentityGenerationAttempt
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Unable to read the approved identity upload.');
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Unsupported approved identity image type.'),
        };
        $dimensions = getimagesizefromstring($contents) ?: [];
        $storedPath = null;

        try {
            return DB::transaction(function () use ($order, $file, $admin, $source, $contents, $mimeType, $extension, $dimensions, &$storedPath): ChildIdentityGenerationAttempt {
                $lockedOrder = Order::query()->with(['story', 'user', 'childIdentityRequest'])->lockForUpdate()->findOrFail($order->id);
                if (! $lockedOrder->story_id) {
                    throw new RuntimeException('Approved child identities can only be attached to story orders.');
                }

                $identity = $lockedOrder->childIdentityRequest;
                if (! $identity || $identity->trashed()) {
                    $identity = $this->createIdentityRequest($lockedOrder);
                    $lockedOrder->forceFill(['child_identity_request_id' => $identity->id])->save();
                }

                $attemptNumber = ((int) $identity->attempts()->max('attempt_number')) + 1;
                $storedPath = 'child-identities/'.$identity->uuid.'/attempts/'.$attemptNumber.'/manual-approved.'.$extension;
                if (! Storage::disk('local')->put($storedPath, $contents)) {
                    throw new RuntimeException('Unable to store the approved identity upload.');
                }

                $prompt = $this->identityPrompts->forOrder($lockedOrder);
                $attempt = $identity->attempts()->create([
                    'attempt_number' => $attemptNumber,
                    'idempotency_key' => (string) Str::uuid(),
                    'initiated_by' => 'admin',
                    'initiated_by_user_id' => $admin->id,
                    'status' => 'succeeded',
                    'provider' => 'manual-upload',
                    'model' => 'manual-approved-identity',
                    'prompt_version' => OrderChildIdentityPromptService::VERSION,
                    'prompt_snapshot' => $prompt,
                    'prompt_hash' => hash('sha256', $prompt),
                    'input_photos_count' => min(255, count($lockedOrder->uploaded_photos ?? [])),
                    'image_size' => isset($dimensions[0], $dimensions[1]) ? $dimensions[0].'x'.$dimensions[1] : 'original',
                    'image_quality' => 'original',
                    'started_at' => now(),
                    'completed_at' => now(),
                    'duration_ms' => 0,
                    'output_disk' => 'local',
                    'output_storage_path' => $storedPath,
                    'output_checksum' => hash('sha256', $contents),
                    'cost_usd' => 0,
                    'cost_calculation_method' => 'calculated',
                    'billing_status' => 'not_billable',
                    'request_metadata' => [
                        'source' => $source,
                        'original_filename' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                    ],
                    'response_metadata' => [
                        'output_mime_type' => $mimeType,
                        'output_extension' => $extension,
                        'width' => $dimensions[0] ?? null,
                        'height' => $dimensions[1] ?? null,
                    ],
                ]);

                $this->approvals->approve($identity, $attempt, $admin, 'admin', true);

                return $attempt->fresh();
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function createIdentityRequest(Order $order): ChildIdentityRequest
    {
        $delivery = $order->delivery_details ?? [];
        $age = is_numeric($order->child_age) ? (int) $order->child_age : null;

        return ChildIdentityRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $order->user_id,
            'resume_token_hash' => hash('sha256', Str::random(80)),
            'parent_name' => data_get($delivery, 'parent_name') ?: $order->user?->name ?: 'عميل HeroKid',
            'parent_phone' => data_get($delivery, 'phone') ?: 'admin-order-'.$order->id,
            'parent_email' => $order->user?->email,
            'child_name' => $order->child_name ?: 'الطفل',
            'child_age' => $age,
            'age_range' => $age ? $age.' سنوات' : ($order->story?->age_range ?: 'غير محدد'),
            'gender' => in_array($order->child_gender, ['boy', 'girl'], true) ? $order->child_gender : null,
            'status' => 'converted',
            'selected_story_id' => $order->story_id,
            'converted_order_id' => $order->id,
            'consent_accepted_at' => $order->created_at ?: now(),
            'consent_version' => 'admin-approved-identity-upload-v1',
            'last_activity_at' => now(),
            'converted_at' => $order->created_at ?: now(),
        ]);
    }
}

<?php

namespace App\Services\Uploads;

use App\Models\Order;
use App\Models\TemporaryPhotoUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TemporaryPhotoUploadService
{
    public function ensureSession(Request $request): array
    {
        if (! $request->session()->has('photo_upload.token')) {
            $request->session()->put('photo_upload.token', Str::random(48));
        }

        $token = (string) $request->session()->get('photo_upload.token');

        return [
            'token' => $token,
            'hash' => $this->sessionHash($token),
        ];
    }

    public function sessionHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function batchHash(string $token): string
    {
        return hash_hmac('sha256', $token !== '' ? $token : 'legacy', (string) config('app.key'));
    }

    public function validateToken(Request $request): string
    {
        $session = $this->ensureSession($request);
        $provided = (string) $request->input('upload_session_token');

        if ($provided === '' || ! hash_equals($session['token'], $provided)) {
            throw new UploadValidationException('انتهت جلسة رفع الصور. حدّث الصفحة وحاول مرة أخرى.', 419);
        }

        return $session['hash'];
    }

    public function upload(Request $request, UploadedFile $file): TemporaryPhotoUpload
    {
        $sessionHash = $this->validateToken($request);
        $batchHash = $this->batchHash((string) $request->input('upload_batch_token'));
        $this->assertBatchCapacity($sessionHash, $batchHash);
        $this->assertValidImage($file);

        $publicId = (string) Str::uuid();
        $extension = $this->extensionForMime((string) $file->getMimeType());
        $path = 'temporary-uploads/child-photos/'.now()->format('Y/m').'/'.$publicId.'.'.$extension;
        $diskName = (string) config('photo_uploads.disk', 'local');
        $checksum = hash_file('sha256', $file->getRealPath());
        $dimensions = $this->dimensions($file);

        try {
            $stored = Storage::disk($diskName)->putFileAs(dirname($path), $file, basename($path));
        } catch (\Throwable $exception) {
            Log::warning('Temporary child photo storage failed.', [
                'exception' => $exception::class,
            ]);

            throw new UploadValidationException('تعذر حفظ الصورة مؤقتاً. حاول مرة أخرى.', 503);
        }

        if (! $stored) {
            throw new UploadValidationException('تعذر حفظ الصورة مؤقتاً. حاول مرة أخرى.', 503);
        }

        return TemporaryPhotoUpload::create([
            'public_id' => $publicId,
            'session_hash' => $sessionHash,
            'batch_hash' => $batchHash,
            'user_id' => $request->user()?->id,
            'disk' => $diskName,
            'path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'checksum' => $checksum ?: null,
            'status' => 'uploaded',
            'expires_at' => now()->addHours((int) config('photo_uploads.temp_retention_hours', 24)),
        ]);
    }

    public function validatedUploadedIds(
        Request $request,
        array $publicIds,
        int $minimum = 1,
        ?int $maximum = null,
    ): Collection {
        $sessionHash = $this->validateToken($request);
        $maximum ??= (int) config('photo_uploads.max_files', 5);
        $publicIds = array_values(array_unique(array_filter($publicIds, 'is_string')));

        if (count($publicIds) < $minimum) {
            $message = $minimum === 1
                ? 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.'
                : 'يرجى رفع صورتين واضحتين للطفل على الأقل.';
            throw new UploadValidationException($message, 422, 'photo_upload_ids');
        }

        if (count($publicIds) > $maximum) {
            throw new UploadValidationException('يمكنك رفع '.$maximum.' صور كحد أقصى.', 422, 'photo_upload_ids');
        }

        $uploads = TemporaryPhotoUpload::whereIn('public_id', $publicIds)->get()->keyBy('public_id');

        if ($uploads->count() !== count($publicIds)) {
            throw new UploadValidationException('بعض الصور المرفوعة غير موجودة أو انتهت صلاحيتها.', 422, 'photo_upload_ids');
        }

        foreach ($publicIds as $publicId) {
            $upload = $uploads->get($publicId);

            if (! $upload?->isAttachableFor($sessionHash, $request->user()?->id)) {
                throw new UploadValidationException('بعض الصور لا تخص جلسة الرفع الحالية أو انتهت صلاحيتها.', 422, 'photo_upload_ids');
            }
        }

        return $uploads
            ->sortBy(fn (TemporaryPhotoUpload $upload) => array_search($upload->public_id, $publicIds, true))
            ->values();
    }

    public function attachIdsToCart(Request $request, array $publicIds, string $cartKey): Collection
    {
        $uploads = $this->validatedUploadedIds($request, $publicIds);

        TemporaryPhotoUpload::whereIn('id', $uploads->pluck('id'))->update([
            'status' => 'attached',
            'attached_cart_key' => $cartKey,
            'user_id' => $request->user()?->id,
            'updated_at' => now(),
        ]);

        return $uploads;
    }

    public function markOrderAttached(array $paths, Order $order): void
    {
        if ($paths === []) {
            return;
        }

        TemporaryPhotoUpload::whereIn('path', $paths)
            ->where('status', 'attached')
            ->whereNull('attached_order_id')
            ->update([
                'attached_order_id' => $order->id,
                'updated_at' => now(),
            ]);
    }

    public function cleanupExpired(int $batchSize = 100): array
    {
        $deleted = 0;
        $expired = 0;

        TemporaryPhotoUpload::query()
            ->whereIn('status', ['uploaded', 'pending', 'failed', 'expired'])
            ->where('expires_at', '<', now())
            ->whereNull('attached_order_id')
            ->orderBy('id')
            ->chunkById($batchSize, function ($uploads) use (&$deleted, &$expired): void {
                foreach ($uploads as $upload) {
                    try {
                        Storage::disk($upload->disk)->delete($upload->path);
                    } catch (\Throwable) {
                        // Missing or inaccessible temporary files should not block cleanup.
                    }

                    $upload->forceFill(['status' => 'expired'])->save();
                    $deleted++;
                    $expired++;
                }
            });

        return ['expired' => $expired, 'deleted_files' => $deleted];
    }

    private function assertBatchCapacity(string $sessionHash, string $batchHash): void
    {
        $count = TemporaryPhotoUpload::where('session_hash', $sessionHash)
            ->where('batch_hash', $batchHash)
            ->where('status', 'uploaded')
            ->where('expires_at', '>', now())
            ->count();

        if ($count >= (int) config('photo_uploads.max_files', 5)) {
            throw new UploadValidationException($this->maxFilesMessage(), 422);
        }
    }

    private function assertValidImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new UploadValidationException('تعذر رفع الصورة. يرجى إعادة اختيار الصورة والمحاولة مرة أخرى.', 422);
        }

        $maxBytes = (int) config('photo_uploads.max_size_mb', 15) * 1024 * 1024;
        if ((int) $file->getSize() > $maxBytes) {
            throw new UploadValidationException('حجم كل صورة يجب ألا يزيد عن '.config('photo_uploads.max_size_mb', 15).' ميجا.', 422);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, config('photo_uploads.allowed_mimes', []), true)) {
            throw new UploadValidationException('صيغة الصورة غير مدعومة. ارفع صور JPG أو PNG أو WebP أو HEIC/HEIF.', 422);
        }

        if (! str_contains($mime, 'heic') && ! str_contains($mime, 'heif') && $this->dimensions($file)['width'] === null) {
            throw new UploadValidationException('الملف المرفوع ليس صورة صالحة أو لا يمكن قراءته.', 422);
        }
    }

    private function dimensions(UploadedFile $file): array
    {
        $dimensions = @getimagesize($file->getRealPath());

        return [
            'width' => is_array($dimensions) ? (int) ($dimensions[0] ?? 0) ?: null : null,
            'height' => is_array($dimensions) ? (int) ($dimensions[1] ?? 0) ?: null : null,
        ];
    }

    private function extensionForMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heic-sequence' => 'heic',
            'image/heif', 'image/heif-sequence' => 'heif',
            default => 'jpg',
        };
    }

    private function maxFilesMessage(): string
    {
        return 'يمكنك رفع '.config('photo_uploads.max_files', 5).' صور كحد أقصى.';
    }
}

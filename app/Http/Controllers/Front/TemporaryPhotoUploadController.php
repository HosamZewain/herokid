<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\TemporaryPhotoUpload;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TemporaryPhotoUploadController extends Controller
{
    public function session(Request $request, TemporaryPhotoUploadService $uploads): JsonResponse
    {
        $session = $uploads->ensureSession($request);

        return response()->json([
            'upload_session_token' => $session['token'],
            'upload_batch_token' => Str::random(48),
            'max_files' => (int) config('photo_uploads.max_files', 3),
            'max_size_mb' => (int) config('photo_uploads.max_size_mb', 15),
            'concurrency' => (int) config('photo_uploads.concurrency', 2),
            'max_long_edge' => (int) config('photo_uploads.max_long_edge', 2560),
            'jpeg_quality' => (int) config('photo_uploads.jpeg_quality', 90),
        ]);
    }

    public function store(Request $request, TemporaryPhotoUploadService $uploads): JsonResponse
    {
        try {
            $file = $request->file('photo');

            if (! $file instanceof UploadedFile) {
                throw new UploadValidationException('يرجى اختيار صورة للرفع.', 422, 'photo');
            }

            if (! $file->isValid()) {
                $message = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'حجم الصورة أكبر من الحد المسموح على الخادم. اختر صورة أصغر من '.config('photo_uploads.max_size_mb', 15).' ميجا.'
                    : 'تعذر استلام الصورة كاملة. أعد اختيارها وحاول مرة أخرى.';

                throw new UploadValidationException($message, 422, 'photo');
            }

            $upload = $uploads->upload($request, $file);

            return response()->json([
                'id' => $upload->public_id,
                'status' => $upload->status,
                'mime_type' => $upload->mime_type,
                'file_size' => $upload->file_size,
                'width' => $upload->width,
                'height' => $upload->height,
                'expires_at' => $upload->expires_at?->toIso8601String(),
                'preview_url' => route('photo-uploads.show', [
                    'publicId' => $upload->public_id,
                    'variant' => $upload->prepared_path ? 'prepared' : 'original',
                ]),
            ], 201);
        } catch (UploadValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'field' => $exception->field,
                'retryable' => $exception->statusCode >= 500,
            ], $exception->statusCode);
        } catch (\Throwable $exception) {
            Log::warning('Unexpected temporary child photo upload failure.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'content_length' => $request->server('CONTENT_LENGTH'),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'حدث خطأ مؤقت أثناء رفع الصورة. حاول مرة أخرى.',
                'retryable' => true,
            ], 503);
        }
    }

    public function show(Request $request, string $publicId, TemporaryPhotoUploadService $uploads)
    {
        $sessionHash = $uploads->sessionHash((string) $request->session()->get('photo_upload.token', ''));

        $upload = TemporaryPhotoUpload::where('public_id', $publicId)
            ->where('session_hash', $sessionHash)
            ->whereIn('status', ['uploaded', 'attached'])
            ->firstOrFail();

        if ($upload->expires_at->isPast() && $upload->status !== 'attached') {
            abort(404);
        }

        $prepared = $request->string('variant')->toString() === 'prepared' && filled($upload->prepared_path);
        $diskName = $prepared ? ($upload->prepared_disk ?: $upload->disk) : $upload->disk;
        $path = $prepared ? $upload->prepared_path : $upload->path;
        $mimeType = $prepared ? $upload->prepared_mime_type : $upload->mime_type;
        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function destroy(Request $request, string $publicId, TemporaryPhotoUploadService $uploads): JsonResponse
    {
        $sessionHash = $uploads->sessionHash((string) $request->session()->get('photo_upload.token', ''));

        $upload = TemporaryPhotoUpload::where('public_id', $publicId)
            ->where('session_hash', $sessionHash)
            ->where('status', 'uploaded')
            ->firstOrFail();

        Storage::disk($upload->disk)->delete($upload->path);

        if ($upload->prepared_path) {
            Storage::disk($upload->prepared_disk ?: $upload->disk)->delete($upload->prepared_path);
        }

        $upload->forceFill(['status' => 'expired'])->save();

        return response()->json(['message' => 'تم حذف الصورة.']);
    }
}

<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use App\Models\TemporaryPhotoUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildIdentityPhotoService
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    public function __construct(private readonly ChildIdentityEventLogger $events) {}

    public function store(ChildIdentityRequest $identity, UploadedFile $file): ChildIdentityPhoto
    {
        if ($identity->validPhotos()->count() >= 5) {
            throw ValidationException::withMessages(['photo' => 'يمكن رفع ٥ صور كحد أقصى.']);
        }

        if (! $file->isValid() || $file->getSize() > 15 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => 'تعذر رفع الصورة أو أن حجمها أكبر من ١٥ ميجابايت.']);
        }

        $contents = $file->get();
        $mime = strtolower((string) $file->getMimeType());
        $imageInfo = @getimagesizefromstring($contents);
        $isHeic = str_contains($mime, 'heic') || str_contains($mime, 'heif');
        $isValidImage = in_array($mime, self::ALLOWED_MIMES, true) && (is_array($imageInfo) || $isHeic);
        $extension = match ($isValidImage ? $mime : '') {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/heic', 'image/heic-sequence' => 'heic',
            'image/heif', 'image/heif-sequence' => 'heif',
            default => 'bin',
        };
        $folder = $isValidImage ? 'originals' : 'quarantine';
        $path = 'child-identities/'.$identity->uuid.'/'.$folder.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        try {
            $photo = $identity->photos()->create([
                'disk' => 'local',
                'path' => $path,
                'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                'mime_type' => $mime,
                'file_size' => strlen($contents),
                'width' => is_array($imageInfo) ? (int) $imageInfo[0] : null,
                'height' => is_array($imageInfo) ? (int) $imageInfo[1] : null,
                'checksum' => hash('sha256', $contents),
                'sort_order' => ((int) $identity->photos()->max('sort_order')) + 1,
                'upload_status' => 'uploaded',
                'validation_status' => $isValidImage ? 'valid' : 'invalid',
                'validation_notes' => $isValidImage ? null : 'Unsupported or unreadable image upload retained in private quarantine.',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        if (! $isValidImage) {
            $this->events->record(
                $identity,
                'photo.rejected',
                'تم حفظ محاولة رفع غير صالحة في الحجر الخاص للمراجعة.',
                ['photo_id' => $photo->id, 'mime_type' => $mime],
            );

            throw ValidationException::withMessages(['photo' => 'ارفع صورة JPG أو PNG أو WebP أو HEIC/HEIF صالحة. تم الاحتفاظ بمحاولة الرفع في السجل الآمن للمراجعة.']);
        }

        return $photo;
    }

    public function adoptTemporaryUploads(ChildIdentityRequest $identity, Collection $uploads): Collection
    {
        return $uploads->values()->map(function (TemporaryPhotoUpload $upload, int $index) use ($identity): ChildIdentityPhoto {
            $extension = match (strtolower($upload->mime_type)) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/heic', 'image/heic-sequence' => 'heic',
                'image/heif', 'image/heif-sequence' => 'heif',
                default => 'jpg',
            };
            $destination = 'child-identities/'.$identity->uuid.'/originals/'.Str::uuid().'.'.$extension;
            $disk = Storage::disk($upload->disk);

            if (! $disk->exists($upload->path) || ! $disk->move($upload->path, $destination)) {
                throw ValidationException::withMessages([
                    'photo_upload_ids' => 'تعذر تثبيت إحدى الصور داخل طلب الهوية. حاول مرة أخرى.',
                ]);
            }

            try {
                $photo = $identity->photos()->create([
                    'disk' => $upload->disk,
                    'path' => $destination,
                    'original_filename' => 'child-photo-'.($index + 1).'.'.$extension,
                    'mime_type' => $upload->mime_type,
                    'file_size' => $upload->file_size,
                    'width' => $upload->width,
                    'height' => $upload->height,
                    'checksum' => $upload->checksum ?: hash('sha256', $disk->get($destination)),
                    'sort_order' => $index + 1,
                    'upload_status' => 'uploaded',
                    'validation_status' => 'valid',
                ]);
                $upload->forceFill([
                    'path' => $destination,
                    'status' => 'attached',
                    'attached_cart_key' => 'child-identity:'.$identity->uuid,
                ])->save();

                return $photo;
            } catch (\Throwable $exception) {
                $disk->move($destination, $upload->path);
                throw $exception;
            }
        });
    }

    public function markRemoved(ChildIdentityPhoto $photo, string $note = 'Removed by customer'): void
    {
        $photo->forceFill([
            'upload_status' => 'removed',
            'validation_status' => 'removed',
            'validation_notes' => $note,
        ])->save();
    }
}

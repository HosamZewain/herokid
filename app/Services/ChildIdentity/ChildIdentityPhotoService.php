<?php

namespace App\Services\ChildIdentity;

use App\Models\ChildIdentityPhoto;
use App\Models\ChildIdentityRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildIdentityPhotoService
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

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
        $isValidImage = in_array($mime, self::ALLOWED_MIMES, true) && is_array($imageInfo);
        $extension = match ($isValidImage ? $mime : '') {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png' => 'png',
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
                'width' => $isValidImage ? (int) $imageInfo[0] : null,
                'height' => $isValidImage ? (int) $imageInfo[1] : null,
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

            throw ValidationException::withMessages(['photo' => 'ارفع صورة JPG أو PNG أو WebP صالحة. تم الاحتفاظ بمحاولة الرفع في السجل الآمن للمراجعة.']);
        }

        return $photo;
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

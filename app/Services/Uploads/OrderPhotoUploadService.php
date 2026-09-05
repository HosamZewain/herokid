<?php

namespace App\Services\Uploads;

use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderPhotoUploadService
{
    /**
     * Remove one photo by its current order-local index.
     *
     * @return array{removed_path: string, total_count: int}
     */
    public function removeAt(Order $order, int $index): array
    {
        $removedPath = DB::transaction(function () use ($order, $index): string {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $photos = is_array($lockedOrder->uploaded_photos)
                ? array_values($lockedOrder->uploaded_photos)
                : [];

            if (! isset($photos[$index]) || ! is_string($photos[$index])) {
                abort(404);
            }

            $removedPath = $photos[$index];
            array_splice($photos, $index, 1);
            $lockedOrder->forceFill(['uploaded_photos' => $photos])->save();

            foreach ($lockedOrder->items()->whereNotNull('personalization_snapshot')->get() as $item) {
                $snapshot = $item->personalization_snapshot ?? [];
                $snapshot['uploaded_photos_count'] = count($photos);
                if (isset($snapshot['fields']['photos']) && is_array($snapshot['fields']['photos'])) {
                    $snapshot['fields']['photos']['value'] = count($photos);
                }
                $item->forceFill(['personalization_snapshot' => $snapshot])->save();
            }

            return $removedPath;
        });

        $stillReferenced = Order::withTrashed()
            ->whereJsonContains('uploaded_photos', $removedPath)
            ->exists();
        if (! $stillReferenced && ! str_contains($removedPath, '..')) {
            Storage::disk((string) config('photo_uploads.disk', 'local'))->delete($removedPath);
        }

        return [
            'removed_path' => $removedPath,
            'total_count' => count($order->fresh()->uploaded_photos ?? []),
        ];
    }

    /**
     * Append supplemental child photos without changing the existing photo indexes.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{added_count: int, total_count: int, files: array<int, array{original_name: string, mime_type: string, size: int}>}
     */
    public function append(Order $order, array $files): array
    {
        if ($files === []) {
            throw ValidationException::withMessages([
                'photos' => 'اختر صورة واحدة واضحة على الأقل لإضافتها إلى الطلب.',
            ]);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'photos' => 'تعذر قراءة بعض الصور المرفوعة. أعد اختيارها وحاول مرة أخرى.',
                ]);
            }

            $this->assertValidImage($file);
        }

        $diskName = (string) config('photo_uploads.disk', 'local');
        $disk = Storage::disk($diskName);
        $storedPaths = [];

        try {
            foreach ($files as $file) {
                $extension = $this->extensionForMime((string) $file->getMimeType());
                $path = 'orders/photos/'.$order->id.'/supplemental/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
                $stored = $disk->putFileAs(dirname($path), $file, basename($path));

                if (! $stored) {
                    throw ValidationException::withMessages([
                        'photos' => 'تعذر حفظ الصور الجديدة. حاول مرة أخرى.',
                    ]);
                }

                $storedPaths[] = $path;
            }

            $totalCount = DB::transaction(function () use ($order, $storedPaths): int {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $currentPhotos = is_array($lockedOrder->uploaded_photos)
                    ? array_values($lockedOrder->uploaded_photos)
                    : [];
                $maximum = (int) config('photo_uploads.admin_max_files', 10);

                if (count($currentPhotos) + count($storedPaths) > $maximum) {
                    throw ValidationException::withMessages([
                        'photos' => 'الحد الأقصى لصور الطفل في الطلب هو '.$maximum.' صور. ارفع عدداً أقل من الصور.',
                    ]);
                }

                $lockedOrder->update([
                    'uploaded_photos' => array_merge($currentPhotos, $storedPaths),
                ]);

                return count($currentPhotos) + count($storedPaths);
            });
        } catch (\Throwable $exception) {
            if ($storedPaths !== []) {
                $disk->delete($storedPaths);
            }

            throw $exception;
        }

        $order->refresh();

        return [
            'added_count' => count($storedPaths),
            'total_count' => $totalCount,
            'files' => collect($files)->map(fn (UploadedFile $file): array => [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ])->all(),
        ];
    }

    private function assertValidImage(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'photos' => 'تعذر رفع إحدى الصور. أعد اختيار الصور وحاول مرة أخرى.',
            ]);
        }

        $maximumSizeMb = (int) config('photo_uploads.max_size_mb', 15);
        if ((int) $file->getSize() > $maximumSizeMb * 1024 * 1024) {
            throw ValidationException::withMessages([
                'photos' => 'حجم كل صورة يجب ألا يزيد عن '.$maximumSizeMb.' ميجا.',
            ]);
        }

        $mime = strtolower((string) $file->getMimeType());
        if (! in_array($mime, config('photo_uploads.allowed_mimes', []), true)) {
            throw ValidationException::withMessages([
                'photos' => 'صيغة الصورة غير مدعومة. ارفع صور JPG أو PNG أو WebP أو HEIC/HEIF.',
            ]);
        }

        if (! str_contains($mime, 'heic') && ! str_contains($mime, 'heif') && @getimagesize($file->getRealPath()) === false) {
            throw ValidationException::withMessages([
                'photos' => 'أحد الملفات المرفوعة ليس صورة صالحة أو لا يمكن قراءته.',
            ]);
        }
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
}

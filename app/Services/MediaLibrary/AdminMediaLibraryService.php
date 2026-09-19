<?php

namespace App\Services\MediaLibrary;

use App\Models\AdminMediaFile;
use App\Models\AdminMediaUploadSession;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminMediaLibraryService
{
    public const MAX_FILE_SIZE = 300 * 1024 * 1024;

    public const CHUNK_SIZE = 1024 * 1024;

    private const ALLOWED_EXTENSIONS = [
        'pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    private const IMAGE_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function start(
        User $admin,
        string $originalName,
        int $totalSize,
        ?string $declaredMime,
        ?string $title = null,
    ): AdminMediaUploadSession {
        $originalName = $this->safeOriginalName($originalName);
        $title = $this->normalizeTitle($title);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file_name' => 'نوع الملف غير مدعوم. المسموح: PDF وTXT وصور JPG وPNG وGIF وWebP.',
            ]);
        }

        if ($totalSize < 1 || $totalSize > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages([
                'size' => 'يجب ألا يزيد حجم الملف على 300 ميجابايت.',
            ]);
        }

        $publicId = (string) Str::uuid();

        return AdminMediaUploadSession::create([
            'public_id' => $publicId,
            'original_name' => $originalName,
            'title' => $title,
            'extension' => $extension,
            'declared_mime' => $declaredMime ? Str::limit($declaredMime, 150, '') : null,
            'total_size' => $totalSize,
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => (int) ceil($totalSize / self::CHUNK_SIZE),
            'next_chunk_index' => 0,
            'bytes_received' => 0,
            'status' => 'uploading',
            'temp_directory' => 'admin/media-library/uploads/'.$publicId,
            'uploaded_by' => $admin->id,
        ]);
    }

    public function appendChunk(AdminMediaUploadSession $upload, User $admin, int $index, UploadedFile $chunk): AdminMediaUploadSession
    {
        return DB::transaction(function () use ($upload, $admin, $index, $chunk): AdminMediaUploadSession {
            /** @var AdminMediaUploadSession $locked */
            $locked = AdminMediaUploadSession::query()->lockForUpdate()->findOrFail($upload->id);
            $this->authorizeOwner($locked, $admin);

            if ($locked->status !== 'uploading') {
                throw ValidationException::withMessages(['chunk' => 'جلسة الرفع غير متاحة لاستقبال أجزاء جديدة.']);
            }

            if ($index !== $locked->next_chunk_index) {
                throw ValidationException::withMessages([
                    'index' => 'ترتيب الجزء غير صحيح. الجزء التالي المطلوب هو '.($locked->next_chunk_index + 1).'.',
                ]);
            }

            $remaining = $locked->total_size - $locked->bytes_received;
            $expectedSize = min($locked->chunk_size, $remaining);
            $actualSize = (int) $chunk->getSize();
            if ($actualSize !== $expectedSize) {
                throw ValidationException::withMessages(['chunk' => 'حجم جزء الرفع غير صحيح. أعد محاولة رفع الملف.']);
            }

            $path = $locked->temp_directory.'/chunks/'.sprintf('%06d.part', $index);
            $stream = fopen($chunk->getRealPath(), 'rb');
            if ($stream === false || ! Storage::disk('local')->put($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new RuntimeException('تعذر حفظ جزء الملف.');
            }
            fclose($stream);

            $locked->update([
                'next_chunk_index' => $index + 1,
                'bytes_received' => $locked->bytes_received + $actualSize,
            ]);

            return $locked->fresh();
        });
    }

    public function complete(AdminMediaUploadSession $upload, User $admin): AdminMediaFile
    {
        $upload = DB::transaction(function () use ($upload, $admin): AdminMediaUploadSession {
            /** @var AdminMediaUploadSession $locked */
            $locked = AdminMediaUploadSession::query()->lockForUpdate()->findOrFail($upload->id);
            $this->authorizeOwner($locked, $admin);

            if ($locked->status !== 'uploading'
                || $locked->next_chunk_index !== $locked->total_chunks
                || $locked->bytes_received !== $locked->total_size) {
                throw ValidationException::withMessages(['file' => 'لم يكتمل رفع جميع أجزاء الملف بعد.']);
            }

            $locked->update(['status' => 'assembling']);

            return $locked->fresh();
        });

        $disk = Storage::disk('local');
        $assembledPath = $upload->temp_directory.'/assembled.tmp';
        $finalPath = null;

        try {
            $disk->makeDirectory($upload->temp_directory);
            $output = fopen($disk->path($assembledPath), 'wb');
            if ($output === false) {
                throw new RuntimeException('تعذر تجهيز الملف النهائي.');
            }

            $hash = hash_init('sha256');
            $assembledSize = 0;
            for ($index = 0; $index < $upload->total_chunks; $index++) {
                $partPath = $upload->temp_directory.'/chunks/'.sprintf('%06d.part', $index);
                $input = fopen($disk->path($partPath), 'rb');
                if ($input === false) {
                    fclose($output);
                    throw new RuntimeException('أحد أجزاء الملف مفقود. أعد محاولة الرفع.');
                }

                while (! feof($input)) {
                    $buffer = fread($input, 1024 * 1024);
                    if ($buffer === false) {
                        fclose($input);
                        fclose($output);
                        throw new RuntimeException('تعذر قراءة أحد أجزاء الملف.');
                    }
                    if ($buffer === '') {
                        continue;
                    }
                    $assembledSize += strlen($buffer);
                    hash_update($hash, $buffer);
                    if (fwrite($output, $buffer) === false) {
                        fclose($input);
                        fclose($output);
                        throw new RuntimeException('تعذر تجميع الملف النهائي.');
                    }
                }
                fclose($input);
            }
            fclose($output);

            if ($assembledSize !== $upload->total_size) {
                throw ValidationException::withMessages(['file' => 'حجم الملف النهائي لا يطابق الملف المرفوع.']);
            }

            $absolutePath = $disk->path($assembledPath);
            $mime = $this->validateCompletedFile($absolutePath, $upload->extension);
            $sha256 = hash_final($hash);
            $finalPath = 'admin/media-library/files/'.now()->format('Y/m').'/'.$upload->public_id.'.'.$upload->extension;

            $disk->makeDirectory(dirname($finalPath));
            if (! $disk->move($assembledPath, $finalPath)) {
                throw new RuntimeException('تعذر نقل الملف إلى المكتبة الدائمة.');
            }

            $media = DB::transaction(function () use ($upload, $admin, $mime, $sha256, $finalPath): AdminMediaFile {
                $media = AdminMediaFile::create([
                    'public_id' => $upload->public_id,
                    'disk' => 'local',
                    'path' => $finalPath,
                    'original_name' => $upload->original_name,
                    'title' => $upload->title,
                    'extension' => $upload->extension,
                    'mime_type' => $mime,
                    'size' => $upload->total_size,
                    'sha256' => $sha256,
                    'uploaded_by' => $admin->id,
                    'uploaded_by_name' => $admin->name,
                ]);

                $upload->delete();

                return $media;
            });

            $disk->deleteDirectory($upload->temp_directory);

            AdminActivityLogger::log(
                'media_library.file_uploaded',
                'تم رفع ملف جديد إلى مكتبة الوسائط.',
                $media,
                [
                    'file_name' => $media->original_name,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'public_id' => $media->public_id,
                ],
                $admin,
            );

            return $media;
        } catch (Throwable $exception) {
            if ($finalPath !== null) {
                $disk->delete($finalPath);
            }
            $disk->delete($assembledPath);
            AdminMediaUploadSession::query()
                ->whereKey($upload->id)
                ->where('status', 'assembling')
                ->update(['status' => 'uploading']);

            throw $exception;
        }
    }

    public function cancel(AdminMediaUploadSession $upload, User $admin): void
    {
        $this->authorizeOwner($upload, $admin);
        Storage::disk('local')->deleteDirectory($upload->temp_directory);
        $upload->delete();
    }

    public function delete(AdminMediaFile $media, User $admin): void
    {
        $disk = Storage::disk($media->disk);
        $trashPath = 'admin/media-library/deleting/'.$media->public_id.'.'.$media->extension;
        $moved = false;

        if ($disk->exists($media->path)) {
            $disk->makeDirectory(dirname($trashPath));
            if (! $disk->move($media->path, $trashPath)) {
                throw new RuntimeException('تعذر تجهيز الملف للحذف. حاول مرة أخرى.');
            }
            $moved = true;
        }

        try {
            DB::transaction(function () use ($media, $admin): void {
                AdminActivityLogger::log(
                    'media_library.file_deleted',
                    'تم حذف ملف من مكتبة الوسائط.',
                    $media,
                    [
                        'file_name' => $media->original_name,
                        'title' => $media->title,
                        'mime_type' => $media->mime_type,
                        'size' => $media->size,
                        'public_id' => $media->public_id,
                    ],
                    $admin,
                );

                $media->delete();
            });
        } catch (Throwable $exception) {
            if ($moved) {
                $disk->move($trashPath, $media->path);
            }

            throw $exception;
        }

        if ($moved) {
            $disk->delete($trashPath);
        }
    }

    /**
     * Remove abandoned upload fragments only. Completed library files are never queried or deleted here.
     */
    public function cleanupAbandonedUploads(int $olderThanHours = 24): int
    {
        $deleted = 0;

        AdminMediaUploadSession::query()
            ->where('updated_at', '<', now()->subHours(max(1, $olderThanHours)))
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use (&$deleted): void {
                foreach ($uploads as $upload) {
                    Storage::disk('local')->deleteDirectory($upload->temp_directory);
                    $upload->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }

    private function validateCompletedFile(string $path, string $extension): string
    {
        if ($extension === 'pdf') {
            $handle = fopen($path, 'rb');
            $header = $handle ? fread($handle, 5) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($header !== '%PDF-') {
                throw ValidationException::withMessages(['file' => 'الملف ليس PDF صالحًا.']);
            }

            return 'application/pdf';
        }

        if ($extension === 'txt') {
            $handle = fopen($path, 'rb');
            $sample = $handle ? fread($handle, 65536) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($sample === false || str_contains($sample, "\0") || ! mb_check_encoding($sample, 'UTF-8')) {
                throw ValidationException::withMessages(['file' => 'ملف TXT غير صالح أو لا يستخدم ترميز UTF-8.']);
            }

            return 'text/plain';
        }

        $imageInfo = @getimagesize($path);
        $expectedMime = self::IMAGE_MIMES[$extension] ?? null;
        if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== $expectedMime) {
            throw ValidationException::withMessages(['file' => 'ملف الصورة غير صالح أو امتداده لا يطابق محتواه.']);
        }

        return $expectedMime;
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\0", '\\'], ['', '/'], $name));
        $name = basename($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['file_name' => 'اسم الملف غير صالح.']);
        }

        return $name;
    }

    private function normalizeTitle(?string $title): ?string
    {
        $title = $title === null ? null : trim($title);
        if ($title === null || $title === '') {
            return null;
        }

        if (mb_strlen($title) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title)) {
            throw ValidationException::withMessages(['title' => 'عنوان الملف غير صالح.']);
        }

        return $title;
    }

    private function authorizeOwner(AdminMediaUploadSession $upload, User $admin): void
    {
        abort_unless($upload->uploaded_by === $admin->id, 403);
    }
}

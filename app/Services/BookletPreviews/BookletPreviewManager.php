<?php

namespace App\Services\BookletPreviews;

use App\Models\BookletPreview;
use App\Models\BookletPreviewVersion;
use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Throwable;

class BookletPreviewManager
{
    public function create(array $attributes, UploadedFile $file, ?User $actor): BookletPreview
    {
        $inspection = $this->inspectUploadedPdf($file);
        $uuid = (string) Str::uuid();
        $token = Str::random(64);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($attributes, $file, $actor, $inspection, $uuid, $token, &$storedPath): BookletPreview {
                $preview = BookletPreview::create([
                    'uuid' => $uuid,
                    'source_type' => $attributes['source_type'],
                    'order_id' => $attributes['order_id'] ?? null,
                    'story_id' => $attributes['story_id'] ?? null,
                    'title' => $attributes['title'],
                    'reading_direction' => $attributes['reading_direction'] ?? 'rtl',
                    'status' => 'active',
                    'public_token_hash' => hash('sha256', $token),
                    'public_token_encrypted' => Crypt::encryptString($token),
                    'show_on_story' => false,
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                ]);

                $version = $this->storeVersion($preview, $file, $inspection, $attributes['note'] ?? null, $actor, 1, $storedPath);
                $preview->update(['current_version_id' => $version->id]);

                return $preview->fresh(['currentVersion', 'story', 'order']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk($this->disk())->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function replace(BookletPreview $preview, UploadedFile $file, ?string $note, ?User $actor): BookletPreview
    {
        $inspection = $this->inspectUploadedPdf($file);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($preview, $file, $note, $actor, $inspection, &$storedPath): BookletPreview {
                $locked = BookletPreview::withTrashed()->lockForUpdate()->findOrFail($preview->id);
                abort_if($locked->trashed(), 422, 'لا يمكن تحديث معاينة موجودة في سلة المحذوفات.');
                $nextVersion = ((int) $locked->versions()->max('version_number')) + 1;
                $version = $this->storeVersion($locked, $file, $inspection, $note, $actor, $nextVersion, $storedPath);
                $locked->update([
                    'current_version_id' => $version->id,
                    'updated_by_user_id' => $actor?->id,
                ]);
                if ($locked->order_id) {
                    Order::query()->whereKey($locked->order_id)->update([
                        'approved_booklet_preview_version_id' => null,
                        'preview_approved_at' => null,
                    ]);
                }

                return $locked->fresh(['currentVersion', 'versions', 'story', 'order']);
            });
        } catch (Throwable $exception) {
            if ($storedPath) {
                Storage::disk($this->disk())->delete($storedPath);
            }

            throw $exception;
        }
    }

    public function createOrReplaceForOrder(Order $order, UploadedFile $file, ?string $note, ?User $actor): BookletPreview
    {
        $existing = $order->bookletPreview()->withTrashed()->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $this->replace($existing->fresh(), $file, $note, $actor);
        }

        return $this->create([
            'source_type' => 'order',
            'order_id' => $order->id,
            'title' => 'معاينة '.$order->story?->title.' — '.$order->order_number,
            'reading_direction' => $order->language === 'en' ? 'ltr' : 'rtl',
            'note' => $note,
        ], $file, $actor);
    }

    public function promoteLegacy(OrderPreview $legacy, ?User $actor): BookletPreview
    {
        $legacy->loadMissing('order.story');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($legacy->file_path), 404, 'ملف المعاينة القديم غير موجود.');

        $absolutePath = $disk->path($legacy->file_path);
        abort_unless(strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'pdf', 422, 'يمكن ترقية ملفات PDF فقط.');

        $uploaded = new UploadedFile(
            $absolutePath,
            basename($absolutePath),
            'application/pdf',
            null,
            true,
        );

        return $this->createOrReplaceForOrder(
            $legacy->order,
            $uploaded,
            $legacy->note ?: 'تمت الترقية من معاينة قديمة.',
            $actor,
        );
    }

    public function updateMetadata(BookletPreview $preview, array $attributes, ?User $actor): BookletPreview
    {
        $preview->update([
            'title' => $attributes['title'],
            'story_id' => $attributes['story_id'] ?? null,
            'source_type' => filled($attributes['story_id'] ?? null) ? 'story' : ($preview->source_type === 'order' ? 'order' : 'standalone'),
            'reading_direction' => $attributes['reading_direction'],
            'updated_by_user_id' => $actor?->id,
        ]);

        if (! $preview->story_id && $preview->show_on_story) {
            $preview->update(['show_on_story' => false]);
        }

        return $preview->fresh(['story', 'order', 'currentVersion']);
    }

    public function publish(BookletPreview $preview, bool $publish, ?User $actor): BookletPreview
    {
        if ($publish && ! $preview->story_id) {
            throw ValidationException::withMessages(['story_id' => 'اربط المعاينة بقصة قبل نشرها على صفحة القصة.']);
        }

        return DB::transaction(function () use ($preview, $publish, $actor): BookletPreview {
            $locked = BookletPreview::lockForUpdate()->findOrFail($preview->id);

            if ($publish) {
                BookletPreview::query()
                    ->where('story_id', $locked->story_id)
                    ->whereKeyNot($locked->id)
                    ->update(['show_on_story' => false]);
            }

            $locked->update([
                'show_on_story' => $publish,
                'updated_by_user_id' => $actor?->id,
            ]);

            return $locked->fresh(['story', 'currentVersion']);
        });
    }

    public function revoke(BookletPreview $preview, string $reason, ?User $actor): BookletPreview
    {
        $preview->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor?->id,
            'revocation_reason' => $reason,
            'updated_by_user_id' => $actor?->id,
        ]);

        return $preview->fresh();
    }

    public function reenable(BookletPreview $preview, ?User $actor): BookletPreview
    {
        $preview->update([
            'status' => 'active',
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'revocation_reason' => null,
            'updated_by_user_id' => $actor?->id,
        ]);

        return $preview->fresh();
    }

    public function delete(BookletPreview $preview, ?User $actor): void
    {
        $preview->update(['updated_by_user_id' => $actor?->id]);
        $preview->delete();
    }

    public function restore(BookletPreview $preview, ?User $actor): BookletPreview
    {
        $preview->restore();
        $preview->update(['updated_by_user_id' => $actor?->id]);

        return $preview->fresh();
    }

    private function storeVersion(
        BookletPreview $preview,
        UploadedFile $file,
        array $inspection,
        ?string $note,
        ?User $actor,
        int $versionNumber,
        ?string &$storedPath,
    ): BookletPreviewVersion {
        $filename = Str::uuid().'.pdf';
        $storedPath = $file->storeAs(
            'booklet-previews/'.$preview->uuid.'/versions/'.$versionNumber,
            $filename,
            $this->disk(),
        );

        if (! $storedPath) {
            throw ValidationException::withMessages(['pdf_file' => 'تعذر حفظ ملف PDF في التخزين الخاص.']);
        }

        return $preview->versions()->create([
            'version_number' => $versionNumber,
            'disk' => $this->disk(),
            'file_path' => $storedPath,
            'original_filename' => Str::limit($file->getClientOriginalName(), 240, ''),
            'mime_type' => 'application/pdf',
            'file_size' => $inspection['file_size'],
            'checksum' => $inspection['checksum'],
            'page_count' => $inspection['page_count'],
            'note' => $note,
            'uploaded_by_user_id' => $actor?->id,
        ]);
    }

    private function inspectUploadedPdf(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['pdf_file' => 'تعذر قراءة ملف PDF المرفوع.']);
        }

        $path = $file->getRealPath();
        $size = $file->getSize() ?: 0;
        $maxBytes = (int) config('booklet_previews.max_upload_mb', 50) * 1024 * 1024;

        if (! $path || $size < 5 || $size > $maxBytes) {
            throw ValidationException::withMessages(['pdf_file' => 'حجم ملف PDF غير صالح أو يتجاوز الحد المسموح.']);
        }

        $handle = fopen($path, 'rb');
        $signature = $handle ? fread($handle, 5) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($signature !== '%PDF-') {
            throw ValidationException::withMessages(['pdf_file' => 'الملف المرفوع ليس ملف PDF صالحًا.']);
        }

        try {
            $tempDir = storage_path('framework/cache/mpdf');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $pageCount = (new Mpdf(['tempDir' => $tempDir]))->setSourceFile($path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['pdf_file' => 'تعذر فتح ملف PDF. تأكد أنه غير مشفر وغير تالف.']);
        }

        if ($pageCount < 1 || $pageCount > (int) config('booklet_previews.max_pages', 100)) {
            throw ValidationException::withMessages(['pdf_file' => 'عدد صفحات الملف يجب أن يكون بين صفحة واحدة و'.config('booklet_previews.max_pages', 100).' صفحة.']);
        }

        return [
            'file_size' => $size,
            'checksum' => hash_file('sha256', $path),
            'page_count' => $pageCount,
        ];
    }

    private function disk(): string
    {
        return (string) config('booklet_previews.disk', 'local');
    }
}

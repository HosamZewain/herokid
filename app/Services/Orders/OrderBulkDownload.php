<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use ZipArchive;

class OrderBulkDownload
{
    public function response(Request $request, Order $representative, string $kind)
    {
        $attachments = $kind === 'attachments';
        $field = $attachments ? 'attachment_ids' : 'preview_ids';
        $data = $request->validate([
            $field => ['required', 'array', 'min:1', 'max:100'],
            $field.'.*' => ['required', 'integer', 'distinct'],
        ], [$field.'.required' => 'حدد ملفًا واحدًا على الأقل.', $field.'.max' => 'حدد حتى 100 ملف في التحميل الواحد.']);
        $ids = array_map('intval', $data[$field]);
        $model = $attachments ? OrderAttachment::class : OrderPreview::class;
        $selected = $model::with($attachments ? ['order'] : ['order', 'productGallery'])->whereIn('id', $ids)->get()->keyBy('id');
        if ($selected->count() !== count($ids) || $selected->contains(fn ($file) => ! $file->order
            || $file->order->checkoutGroupKey() !== $representative->checkoutGroupKey()
            || (! $attachments && $file->productGallery?->checkout_group_key !== $representative->checkoutGroupKey()))) {
            throw ValidationException::withMessages([$field => 'بعض الملفات المحددة لا تنتمي إلى الطلب الحالي. حدّث الصفحة وأعد التحديد.']);
        }
        foreach ($selected as $file) {
            app(OrderAccess::class)->assert($file->order);
        }

        $directory = sys_get_temp_dir().'/herokid-download-'.Str::uuid();
        abort_unless(mkdir($directory, 0700), 503, 'تعذر تجهيز التحميل. حاول مرة أخرى.');
        $archive = $directory.'/files.zip';
        $zip = new ZipArchive;
        $opened = false;
        $paths = [];
        $bytes = 0;
        try {
            abort_unless($zip->open($archive, ZipArchive::CREATE) === true, 503, 'تعذر تجهيز ملف ZIP.');
            $opened = true;
            foreach ($ids as $index => $id) {
                $file = $selected[$id];
                $path = $attachments ? $file->path : $file->file_path;
                abort_if(! $path || str_contains($path, '..') || str_starts_with($path, '/'), 404);
                $input = Storage::disk($file->disk ?: 'local')->readStream($path);
                abort_unless(is_resource($input), 422, 'أحد الملفات المحددة غير متاح. حدّث الصفحة وأعد المحاولة.');
                $local = $directory.'/file-'.$id;
                $paths[] = $local;
                $output = fopen($local, 'wb');
                try {
                    abort_unless(is_resource($output), 503, 'تعذر تجهيز التحميل.');
                    // Bound disk usage using actual streamed bytes, never trust stored size metadata.
                    $copied = stream_copy_to_stream($input, $output, max(1, 512 * 1024 * 1024 - $bytes + 1));
                    abort_if($copied === false, 503, 'تعذر قراءة أحد الملفات المحددة.');
                    $bytes += $copied;
                    abort_if($bytes > 512 * 1024 * 1024, 422, 'حجم الملفات المحددة أكبر من 512 ميجا. حمّلها على دفعات أصغر.');
                } finally {
                    fclose($input);
                    if (is_resource($output)) {
                        fclose($output);
                    }
                }
                if ($file->checksum) {
                    abort_unless(hash_equals($file->checksum, hash_file('sha256', $local)), 422, 'تعذر التحقق من أحد الملفات المحددة. أعد المحاولة أو راجع الملف.');
                }
                $name = basename(str_replace('\\', '/', $file->original_name ?: basename($path)));
                $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '-', $name) ?: 'file';
                // Preserve selection/display order and keep duplicate names distinct.
                $entry = sprintf('%03d-%d-', $index + 1, $id).mb_substr($name, 0, 150);
                abort_unless($zip->addFile($local, $entry), 503, 'تعذر إضافة أحد الملفات إلى ZIP.');
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
            abort_unless($zip->close(), 503, 'تعذر إكمال ملف ZIP.');
            $opened = false;
            foreach ($paths as $path) {
                @unlink($path);
            }
            $reference = app(CustomerPreviewPage::class)->reference($representative->checkoutGroupKey()) ?: 'order-'.$representative->id;
            $name = preg_replace('/[^A-Za-z0-9_-]/', '-', $reference).'-'.$kind.'.zip';

            return response()->streamDownload(function () use ($archive, $directory) {
                try {
                    readfile($archive);
                } finally {
                    @unlink($archive);
                    @rmdir($directory);
                }
            }, $name, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $error) {
            if ($opened) {
                $zip->close();
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            @unlink($archive);
            @rmdir($directory);
            if ($error instanceof HttpExceptionInterface) {
                throw $error;
            }
            report($error);
            abort(503, 'تعذر تجهيز الملفات المحددة. حاول مرة أخرى أو راجع الملفات الناقصة.');
        }
    }
}

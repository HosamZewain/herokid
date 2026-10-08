<?php

namespace App\Services\DatabaseExports;

use App\Models\DatabaseExport;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DatabaseExportService
{
    public function __construct(private readonly DatabaseDumpWriter $writer) {}

    public function request(User $user): DatabaseExport
    {
        if (! $this->writer->available()) {
            throw ValidationException::withMessages(['export' => 'التصدير يحتاج MySQL وأداة mysqldump وصلاحية تشغيلها على السيرفر. لم يتم إنشاء ملف ناقص.']);
        }
        $this->privateDisks();
        $export = Cache::lock('database-export-request', 10)->block(3, function () use ($user) {
            if (DatabaseExport::whereIn('status', ['queued', 'processing'])->exists()) {
                throw ValidationException::withMessages(['export' => 'يوجد تصدير قيد التجهيز بالفعل. انتظر انتهاءه قبل إنشاء نسخة أخرى.']);
            }

            return DB::transaction(function () use ($user) {
                $export = DatabaseExport::create(['uuid' => (string) Str::uuid(), 'requested_by' => $user->id, 'status' => 'queued', 'expires_at' => now()->addHours(2)]);
                AdminActivityLogger::log('database_export.requested', 'طلب تصدير كامل لقاعدة البيانات', $export, admin: $user);

                return $export;
            });
        });

        return $export;
    }

    public function processPending(): bool
    {
        $export = DatabaseExport::where('status', 'queued')->oldest('id')->first();
        if (! $export || DatabaseExport::whereKey($export->id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now()]) !== 1) {
            return false;
        }
        $directory = 'admin/database-exports/work/'.$export->uuid;
        $finalPath = 'admin/database-exports/files/'.$export->uuid.'.sql.gz';
        $uploaded = false;
        $processing = $private = null;
        try {
            [$processingName, $privateName] = $this->privateDisks();
            $processing = Storage::disk($processingName);
            $private = Storage::disk($privateName);
            $processing->makeDirectory($directory);
            chmod($processing->path($directory), 0700);
            $sql = $processing->path($directory.'/database.sql');
            $compressed = $processing->path($directory.'/database.sql.gz');
            $this->writer->write($sql);
            $this->compress($sql, $compressed);
            $stream = fopen($compressed, 'rb');
            try {
                $uploaded = true; // Also remove a partial object when a driver fails mid-write.
                if ($stream === false || ! $private->writeStream($finalPath, $stream, ['visibility' => 'private'])) {
                    throw new RuntimeException('STORAGE_FAILED');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $export->forceFill(['status' => 'ready', 'disk' => $privateName, 'path' => $finalPath,
                'filename' => 'herokid-database-'.now()->format('Ymd-His').'.sql.gz',
                'size' => filesize($compressed), 'sha256' => hash_file('sha256', $compressed),
                'completed_at' => now(), 'expires_at' => now()->addHours((int) config('database_exports.retention_hours', 24))])->save();
        } catch (Throwable $e) {
            if ($uploaded && $private) {
                try {
                    $private->delete($finalPath);
                } catch (Throwable) {
                    // Keep the deterministic path for a later cleanup retry, never expose it.
                }
            }
            // Never persist raw process/connection exception text: it may contain credentials.
            $safe = in_array($e->getMessage(), ['DUMP_UNAVAILABLE', 'NON_TRANSACTIONAL_TABLES', 'STORAGE_FAILED'], true) ? $e->getMessage() : 'DUMP_FAILED';
            DatabaseExport::whereKey($export->id)->update(['status' => 'failed', 'error_code' => $safe, 'disk' => $uploaded ? $privateName : null, 'path' => $uploaded ? $finalPath : null, 'size' => null, 'sha256' => null]);
        } finally {
            try {
                $processing?->deleteDirectory($directory);
            } catch (Throwable) {
                // The cleanup command retries stale private working directories.
            }
        }

        return true;
    }

    public function cleanup(): int
    {
        $count = 0;
        foreach (DatabaseExport::where('expires_at', '<=', now())->whereIn('status', ['ready', 'queued', 'failed'])->cursor() as $export) {
            $expected = 'admin/database-exports/files/'.$export->uuid.'.sql.gz';
            if ($export->path && $export->path === $expected && $export->disk) {
                try {
                    $removed = Storage::disk($export->disk)->delete($expected);
                } catch (Throwable) {
                    $removed = false;
                }
                if (! $removed) {
                    continue;
                }
            }
            $export->update(['status' => 'expired', 'path' => null]);
            $count++;
        }
        // Recover after worker termination; never touch the database or permanent media.
        foreach (DatabaseExport::where('status', 'processing')->where('started_at', '<', now()->subMinutes(30))->cursor() as $export) {
            $export->update(['status' => 'failed', 'error_code' => 'WORKER_INTERRUPTED', 'expires_at' => now(),
                'disk' => config('media.private_disk', 'local'), 'path' => 'admin/database-exports/files/'.$export->uuid.'.sql.gz']);
        }
        $processingName = config('media.processing_disk', 'local');
        if (config("filesystems.disks.{$processingName}.driver") === 'local') {
            $processing = Storage::disk($processingName);
            foreach ($processing->directories('admin/database-exports/work') as $directory) {
                if ($processing->lastModified($directory) < now()->subDay()->timestamp) {
                    $processing->deleteDirectory($directory);
                }
            }
        }

        return $count;
    }

    public function available(): bool
    {
        return $this->writer->available();
    }

    private function privateDisks(): array
    {
        $processing = config('media.processing_disk', 'local');
        $private = config('media.private_disk', 'local');
        foreach ([$processing, $private] as $name) {
            $root = config("filesystems.disks.{$name}.root");
            if ($root && (str_starts_with(rtrim($root, '/').'/', rtrim(public_path(), '/').'/')
                || str_starts_with(rtrim($root, '/').'/', rtrim(storage_path('app/public'), '/').'/'))) {
                throw ValidationException::withMessages(['export' => 'لا يمكن حفظ قاعدة البيانات داخل مجلد عام.']);
            }
        }
        if (config("filesystems.disks.{$processing}.driver") !== 'local' || config("filesystems.disks.{$processing}.visibility") === 'public'
            || $processing === 'public' || $private === 'public' || config("filesystems.disks.{$private}.visibility") === 'public') {
            throw ValidationException::withMessages(['export' => 'إعدادات تخزين النسخة غير آمنة. استخدم قرص معالجة محليًا وقرصًا دائمًا خاصًا.']);
        }

        return [$processing, $private];
    }

    private function compress(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($target, 'wb6');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('STORAGE_FAILED');
        }
        chmod($target, 0600);
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk))) {
                    throw new RuntimeException('STORAGE_FAILED');
                }
            }
        } finally {
            fclose($input);
            if (! gzclose($output)) {
                throw new RuntimeException('STORAGE_FAILED');
            }
        }
    }
}

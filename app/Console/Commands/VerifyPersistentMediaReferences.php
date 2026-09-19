<?php

namespace App\Console\Commands;

use App\Services\Storage\PersistentMediaReferenceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VerifyPersistentMediaReferences extends Command
{
    protected $signature = 'media:verify-s3-references
        {--source-private=local : Current private disk reference}
        {--source-public=public : Current public disk reference}
        {--target-private=s3_private : Target scoped private disk}
        {--target-public=s3_public : Target scoped public disk}
        {--missing-only : Print only missing or unreadable references}';

    protected $description = 'Verify persistent database media references against scoped S3 disks without changing data';

    public function handle(PersistentMediaReferenceRegistry $registry): int
    {
        $mapping = [
            (string) $this->option('source-private') => (string) $this->option('target-private'),
            (string) $this->option('source-public') => (string) $this->option('target-public'),
        ];
        $rows = [];
        $totals = ['total' => 0, 'present' => 0, 'missing' => 0, 'errors' => 0];

        foreach ($registry->references() as $reference) {
            DB::table($reference['table'])
                ->select(array_merge(['id', $reference['disk']], $reference['paths']))
                ->whereIn($reference['disk'], array_keys($mapping))
                ->where(function ($query) use ($reference): void {
                    foreach ($reference['paths'] as $pathColumn) {
                        $query->orWhere(fn ($path) => $path->whereNotNull($pathColumn)->where($pathColumn, '<>', ''));
                    }
                })
                ->orderBy('id')
                ->chunkById(250, function ($records) use ($reference, $mapping, &$rows, &$totals): void {
                    foreach ($records as $record) {
                        $sourceDisk = (string) $record->{$reference['disk']};
                        $targetDisk = $mapping[$sourceDisk];
                        foreach ($reference['paths'] as $pathColumn) {
                            $path = (string) ($record->{$pathColumn} ?? '');
                            if ($path === '') {
                                continue;
                            }

                            $status = 'present';
                            try {
                                if (! Storage::disk($targetDisk)->exists($path)) {
                                    $status = 'missing';
                                }
                            } catch (Throwable) {
                                $status = 'error';
                            }

                            $totals['total']++;
                            $totals[$status === 'error' ? 'errors' : $status]++;

                            if (! $this->option('missing-only') || $status !== 'present') {
                                $rows[] = [
                                    $reference['table'].'.'.$pathColumn,
                                    $reference['model'].'#'.$record->id,
                                    $sourceDisk.' → '.$targetDisk,
                                    $path,
                                    $status,
                                ];
                            }
                        }
                    }
                }, 'id');
        }

        foreach ($registry->implicitReferences() as $reference) {
            $sourceDisk = $reference['scope'] === 'public'
                ? (string) $this->option('source-public')
                : (string) $this->option('source-private');
            $targetDisk = $reference['scope'] === 'public'
                ? (string) $this->option('target-public')
                : (string) $this->option('target-private');
            $columns = array_merge($reference['paths'], $reference['json_paths']);

            DB::table($reference['table'])
                ->select(array_merge(['id'], $columns))
                ->where(function ($query) use ($columns): void {
                    foreach ($columns as $pathColumn) {
                        $query->orWhere(fn ($path) => $path->whereNotNull($pathColumn)->where($pathColumn, '<>', ''));
                    }
                })
                ->orderBy('id')
                ->chunkById(250, function ($records) use ($reference, $sourceDisk, $targetDisk, &$rows, &$totals): void {
                    foreach ($records as $record) {
                        foreach ($reference['paths'] as $pathColumn) {
                            $path = (string) ($record->{$pathColumn} ?? '');
                            if ($path !== '') {
                                $this->inspect($reference, $record->id, $pathColumn, $path, $sourceDisk, $targetDisk, $rows, $totals);
                            }
                        }

                        foreach ($reference['json_paths'] as $pathColumn) {
                            $value = $record->{$pathColumn} ?? null;
                            $decoded = is_string($value) ? json_decode($value, true) : $value;
                            foreach (is_array($decoded) ? $decoded : [] as $path) {
                                if (is_string($path) && $path !== '') {
                                    $this->inspect($reference, $record->id, $pathColumn, $path, $sourceDisk, $targetDisk, $rows, $totals);
                                }
                            }
                        }
                    }
                }, 'id');
        }

        $this->table(['Table', 'Model / ID', 'Disk', 'Path', 'S3 status'], $rows);
        $this->table(['Total references', 'Present on S3', 'Missing on S3', 'Read errors'], [[
            $totals['total'], $totals['present'], $totals['missing'], $totals['errors'],
        ]]);
        $this->info('Verification only. No database or storage changes were made.');

        return ($totals['missing'] + $totals['errors']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function inspect(array $reference, int|string $id, string $pathColumn, string $path, string $sourceDisk, string $targetDisk, array &$rows, array &$totals): void
    {
        $status = 'present';
        try {
            if (! Storage::disk($targetDisk)->exists($path)) {
                $status = 'missing';
            }
        } catch (Throwable) {
            $status = 'error';
        }

        $totals['total']++;
        $totals[$status === 'error' ? 'errors' : $status]++;

        if (! $this->option('missing-only') || $status !== 'present') {
            $rows[] = [
                $reference['table'].'.'.$pathColumn,
                $reference['model'].'#'.$id,
                $sourceDisk.' → '.$targetDisk.' (implicit)',
                $path,
                $status,
            ];
        }
    }
}

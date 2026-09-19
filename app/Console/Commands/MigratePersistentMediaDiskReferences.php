<?php

namespace App\Console\Commands;

use App\Services\Storage\PersistentMediaReferenceRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigratePersistentMediaDiskReferences extends Command
{
    protected $signature = 'media:migrate-disk-references
        {--apply : Apply eligible reference updates; omitted means dry-run}
        {--source-private=local : Current private disk reference}
        {--source-public=public : Current public disk reference}
        {--target-private=s3_private : Target scoped private disk}
        {--target-public=s3_public : Target scoped public disk}';

    protected $description = 'Safely migrate persistent media disk references only when the target object exists';

    public function handle(PersistentMediaReferenceRegistry $registry): int
    {
        $apply = (bool) $this->option('apply');
        $mapping = [
            (string) $this->option('source-private') => (string) $this->option('target-private'),
            (string) $this->option('source-public') => (string) $this->option('target-public'),
        ];
        $candidates = 0;
        $eligible = 0;
        $missing = 0;
        $errors = 0;
        $updated = 0;
        $details = [];

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
                ->chunkById(250, function ($records) use ($reference, $mapping, $apply, &$candidates, &$eligible, &$missing, &$errors, &$updated, &$details): void {
                    foreach ($records as $record) {
                        $candidates++;
                        $sourceDisk = (string) $record->{$reference['disk']};
                        $targetDisk = $mapping[$sourceDisk];
                        $paths = collect($reference['paths'])
                            ->mapWithKeys(fn (string $column): array => [$column => (string) ($record->{$column} ?? '')])
                            ->filter()
                            ->all();

                        try {
                            $missingPaths = collect($paths)
                                ->reject(fn (string $path): bool => Storage::disk($targetDisk)->exists($path));
                        } catch (Throwable) {
                            $errors++;
                            $details[] = [$reference['table'], $record->id, $sourceDisk, $targetDisk, implode(', ', $paths), 'error'];

                            continue;
                        }

                        if ($missingPaths->isNotEmpty()) {
                            $missing++;
                            $details[] = [$reference['table'], $record->id, $sourceDisk, $targetDisk, $missingPaths->implode(', '), 'missing'];

                            continue;
                        }

                        $eligible++;
                        if ($apply) {
                            $updated += DB::transaction(fn (): int => DB::table($reference['table'])
                                ->where('id', $record->id)
                                ->where($reference['disk'], $sourceDisk)
                                ->update([$reference['disk'] => $targetDisk]));
                        }
                    }
                }, 'id');
        }

        if ($details !== []) {
            $this->table(['Table', 'ID', 'Source', 'Target', 'Path', 'Status'], $details);
        }
        $this->table(['Mode', 'Candidates', 'Eligible', 'Missing', 'Errors', 'Updated'], [[
            $apply ? 'APPLY' : 'DRY RUN', $candidates, $eligible, $missing, $errors, $updated,
        ]]);
        $this->info($apply
            ? 'Eligible disk references were updated. No files were copied or deleted.'
            : 'No rows changed. Re-run with --apply only after reviewing verification output.');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

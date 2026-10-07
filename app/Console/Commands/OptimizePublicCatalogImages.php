<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePublicImageVariants;
use App\Models\PublicImageVariant;
use App\Models\Setting;
use App\Services\Images\PublicCatalogImageOptimizer;
use App\Services\Images\PublicCatalogImageSources;
use App\Services\Images\PublicImageSource;
use Illuminate\Console\Command;
use Throwable;

class OptimizePublicCatalogImages extends Command
{
    protected $signature = 'images:optimize-catalog {--apply : Generate or queue variants; otherwise dry run} {--sync : Generate sequentially in this command, never a web request} {--limit=200 : Maximum images per invocation} {--force : Recheck previously generated images}';

    protected $description = 'Safely prepare display-only WebP variants for referenced public catalog images; originals are never changed';

    public function handle(PublicCatalogImageOptimizer $optimizer): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 5000) {
            $this->error('Limit must be between 1 and 5000.');

            return self::INVALID;
        }
        $disk = (string) config('media.public_disk', 'public');
        $sources = [];
        foreach (PublicCatalogImageSources::FIELDS as $modelClass => $fields) {
            foreach ($modelClass::query()->select(array_unique(['id', ...$fields, ...($modelClass === Setting::class ? ['key'] : [])]))->cursor() as $model) {
                foreach (PublicCatalogImageSources::paths($model) as $path) {
                    $sources[PublicImageSource::key($disk, $path)] = $path;
                }
            }
        }
        $ready = PublicImageVariant::whereIn('source_key', array_keys($sources))->pluck('source_key')->all();
        $pending = $this->option('force') ? $sources : array_diff_key($sources, array_flip($ready));
        $batch = array_slice($pending, 0, $limit, true);
        $completed = $failed = 0;
        if ($this->option('apply')) {
            foreach ($batch as $path) {
                try {
                    if ($this->option('sync')) {
                        $optimizer->generate($disk, $path, (bool) $this->option('force'));
                    } else {
                        GeneratePublicImageVariants::dispatch($disk, $path, (bool) $this->option('force'))
                            ->onConnection((string) config('public_images.queue_connection'))->afterCommit();
                    }
                    $completed++;
                } catch (Throwable $error) {
                    $failed++;
                    // Catalog path/class only. Never print unrelated order/customer data.
                    $this->warn('Skipped '.$path.' ('.class_basename($error).'). Original remains available.');
                }
            }
        }
        $mode = ! $this->option('apply') ? 'DRY RUN' : ($this->option('sync') ? 'SYNC' : 'QUEUED');
        $this->table(['Mode', 'Referenced', 'Already prepared', 'Pending', 'This batch', 'Processed/queued', 'Failed'], [[$mode, count($sources), count($ready), count($pending), count($batch), $completed, $failed]]);
        if (! $this->option('apply')) {
            $this->info('No files or records changed. Use --apply after reviewing the counts.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Observers;

use App\Jobs\GeneratePublicImageVariants;
use App\Services\Images\PublicCatalogImageSources;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicCatalogImageObserver
{
    public function created(Model $model): void
    {
        $this->queue($model);
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged(PublicCatalogImageSources::FIELDS[$model::class] ?? [])) {
            $this->queue($model);
        }
    }

    private function queue(Model $model): void
    {
        $paths = PublicCatalogImageSources::paths($model);
        if ($paths === []) {
            return;
        }
        DB::afterCommit(function () use ($paths): void {
            foreach ($paths as $path) {
                try {
                    GeneratePublicImageVariants::dispatch((string) config('media.public_disk', 'public'), $path)
                        ->onConnection((string) config('public_images.queue_connection'));
                } catch (Throwable $error) {
                    // Image optimization must never prevent a catalog edit from being saved.
                    Log::warning('Public image optimization could not be queued.', ['exception_type' => $error::class]);
                }
            }
        });
    }
}

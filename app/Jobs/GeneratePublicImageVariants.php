<?php

namespace App\Jobs;

use App\Services\Images\PublicCatalogImageOptimizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePublicImageVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $disk, public string $path, public bool $force = false) {}

    public function handle(PublicCatalogImageOptimizer $optimizer): void
    {
        $optimizer->generate($this->disk, $this->path, $this->force);
    }
}

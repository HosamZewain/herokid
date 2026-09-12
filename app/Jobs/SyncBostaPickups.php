<?php

namespace App\Jobs;

use App\Services\Bosta\BostaPickupSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBostaPickups implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    // Stay below the default 90-second queue retry lease to avoid concurrent work.
    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public bool $force = false) {}

    public function handle(BostaPickupSyncService $sync): void
    {
        $sync->syncIfDue($this->force);
    }
}

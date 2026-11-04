<?php

namespace App\Jobs;

use App\Services\Agentic\ProductionExecutor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchAutoPilotActions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onConnection('agentic-shadow')->onQueue('agentic-shadow');
    }

    public function handle(ProductionExecutor $executor): void
    {
        $executor->dispatchPass();
    }
}

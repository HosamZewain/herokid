<?php

namespace App\Jobs;

use App\Services\OrderAutomation\Process\ProcessRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdvanceOrderProcess implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('order-process')->onQueue('order-process');
    }

    public function handle(ProcessRuntime $runtime): void
    {
        $runtime->advance($this->runId);
    }
}

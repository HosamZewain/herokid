<?php

namespace App\Jobs;

use App\Services\OrderAutomation\Process\ProcessRuntime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RetryOrderProcessMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public readonly int $runId, public readonly int $stepId, public readonly int $attemptId, public readonly int $version)
    {
        $this->onConnection('order-process')->onQueue('order-process');
    }

    public function handle(ProcessRuntime $runtime): void
    {
        $runtime->retryMessage($this->runId, $this->stepId, $this->attemptId, $this->version);
    }
}

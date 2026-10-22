<?php

namespace App\Jobs;

use App\Services\OrderAutomation\WorkflowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AdvanceOrderAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('order-automation')->onQueue(config('order_automation.queue'));
    }

    public function handle(WorkflowRunner $runner): void
    {
        $runner->advance($this->runId);
    }
}

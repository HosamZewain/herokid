<?php

namespace App\Jobs;

use App\Services\OrderAutomation\Process\ProcessIdentityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateProcessIdentity implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $identityId)
    {
        $this->onConnection('order-process-ai')->onQueue('order-process-ai');
    }

    public function handle(ProcessIdentityService $service): void
    {
        $service->generate($this->identityId);
    }
}

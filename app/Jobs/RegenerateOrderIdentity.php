<?php

namespace App\Jobs;

use App\Services\Orders\OrderIdentityRegenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegenerateOrderIdentity implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $regenerationId)
    {
        $this->onConnection('order-process-ai')->onQueue('order-process-ai');
    }

    public function failed(?\Throwable $error): void
    {
        app(OrderIdentityRegenerationService::class)->fail($this->regenerationId);
    }

    public function handle(OrderIdentityRegenerationService $service): void
    {
        $service->generate($this->regenerationId);
    }
}

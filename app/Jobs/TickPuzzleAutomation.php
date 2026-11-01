<?php

namespace App\Jobs;

use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TickPuzzleAutomation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection('order-process-ai')->onQueue('order-process-ai');
    }

    public function handle(PuzzleAutomation $service): void
    {
        $service->tick();
    }
}

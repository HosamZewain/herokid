<?php

namespace App\Jobs;

use App\Services\Agentic\DecisionOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateAgenticShadow implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 1000;
    public int $uniqueFor = 1200;

    public function __construct()
    {
        $this->onConnection('agentic-shadow')->onQueue('agentic-shadow');
    }

    public function handle(DecisionOrchestrator $orchestrator): void
    {
        $orchestrator->evaluatePass();
    }
}

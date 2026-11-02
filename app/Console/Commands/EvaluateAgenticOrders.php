<?php

namespace App\Console\Commands;

use App\Services\Agentic\DecisionOrchestrator;
use Illuminate\Console\Command;

class EvaluateAgenticOrders extends Command
{
    protected $signature = 'agentic:evaluate';
    protected $description = 'Run one bounded Shadow decision pass; never execute business actions';

    public function handle(DecisionOrchestrator $orchestrator): int
    {
        $this->line(json_encode($orchestrator->evaluatePass(), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}

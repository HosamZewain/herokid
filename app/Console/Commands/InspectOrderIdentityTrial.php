<?php

namespace App\Console\Commands;

use App\Services\OrderAutomation\Process\ProcessIdentityPreflight;
use Illuminate\Console\Command;
use Throwable;

class InspectOrderIdentityTrial extends Command
{
    protected $signature = 'order-process:identity-preflight {order} {--automation=} {--actor=}';

    protected $description = 'Read-only identity trial candidate check; no generation, messages, enrollment or activation';

    public function handle(ProcessIdentityPreflight $preflight): int
    {
        $ids = [$this->argument('order'), $this->option('automation'), $this->option('actor')];
        if (collect($ids)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Positive order, automation and actor IDs are required.');

            return self::INVALID;
        }
        try {
            $report = $preflight->inspect(...array_map('intval', $ids));
        } catch (Throwable) {
            $this->error('Preflight could not complete. No execution was requested; inspect securely.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['candidate_valid'] ? self::SUCCESS : self::FAILURE;
    }
}

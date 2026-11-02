<?php

namespace App\Console\Commands;

use App\Services\Agentic\EvaluationRunner;
use App\Services\Agentic\Providers\EvaluationBudgetProvider;
use App\Services\Agentic\Providers\MockProvider;
use Illuminate\Console\Command;

class BenchmarkAgenticDecisions extends Command
{
    protected $signature = 'agentic:benchmark {--provider=mock} {--dataset=actual} {--output=}';

    protected $description = 'Evaluate a labelled anonymized corpus locally; no business mutations';

    public function handle(EvaluationRunner $runner): int
    {
        if (! app()->environment(['local', 'testing']) || ! in_array($this->option('provider'), ['mock', 'codex'], true)
            || ! in_array($this->option('dataset'), ['actual', 'synthetic'], true)) {
            return self::FAILURE;
        }
        $path = base_path('tests/Fixtures/agentic/'.$this->option('dataset').'-orders.v1.json');
        $corpus = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $provider = $this->option('provider') === 'codex' ? app(EvaluationBudgetProvider::class) : app(MockProvider::class);
        if ($provider->name() === 'codex' && ($corpus['approval'] !== 'owner_approved' || ! $this->option('output') || ! ($provider->health()['ready'] ?? false))) {
            $this->error('Real benchmark refused: approved corpus, isolated subscription provider and report path are required.');

            return self::FAILURE;
        }
        $file = null;
        if ($output = $this->option('output')) {
            // Never overwrite an earlier evaluation record.
            $file = @fopen($output, 'x');
            if (! $file) {
                $this->error('Report destination already exists or is unavailable.');

                return self::FAILURE;
            }
        }
        try {
            $report = $runner->run($corpus, $provider);
            $report['dataset_sha256'] = hash_file('sha256', $path);
            if ($file) {
                fwrite($file, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            }
        } finally {
            if ($file) {
                fclose($file);
            }
        }
        unset($report['results']);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['accuracy_percent'] >= 95 && $report['unsafe_proposals'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}

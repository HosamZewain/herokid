<?php

namespace App\Console\Commands;

use App\Services\Agentic\ContextFingerprint;
use App\Services\Agentic\EvaluationRunner;
use App\Services\Agentic\Providers\EvaluationBudgetProvider;
use App\Services\Agentic\Providers\MockProvider;
use Illuminate\Console\Command;

class BenchmarkAgenticDecisions extends Command
{
    protected $signature = 'agentic:benchmark {--provider=mock} {--dataset=actual} {--memory-snapshot=} {--output=}';

    protected $description = 'Evaluate a labelled anonymized corpus locally; no business mutations';

    public function handle(EvaluationRunner $runner): int
    {
        if (! app()->environment(['local', 'testing']) || ! in_array($this->option('provider'), ['mock', 'codex'], true)
            || ! in_array($this->option('dataset'), ['actual', 'synthetic'], true)) {
            return self::FAILURE;
        }
        $path = base_path('tests/Fixtures/agentic/'.$this->option('dataset').'-orders.'.($this->option('dataset') === 'actual' ? 'v3' : 'v1').'.json');
        $corpus = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $memoryPath = $this->option('memory-snapshot');
        if ($memoryPath) {
            $snapshot = json_decode(file_get_contents($memoryPath), true, 64, JSON_THROW_ON_ERROR);
            $memory = $snapshot['memory'] ?? null;
            $valid = is_array($memory) && ($memory['overflow'] ?? null) === false
                && is_array($memory['entries'] ?? null) && count($memory['entries']) >= 1 && count($memory['entries']) <= 30;
            if ($valid) {
                $ids = [];
                foreach ($memory['entries'] as $entry) {
                    if (! is_array($entry) || ! is_int($entry['id'] ?? null) || $entry['id'] < 1
                        || ! is_int($entry['revision'] ?? null) || $entry['revision'] < 1
                        || ($entry['scope'] ?? null) !== 'global'
                        || ! in_array($entry['source'] ?? null, ['owner_review', 'staff_correction'], true)
                        || ! is_string($entry['title'] ?? null) || mb_strlen($entry['title']) > 160
                        || ! is_string($entry['guidance'] ?? null) || mb_strlen($entry['guidance']) > 1200
                        || preg_match('/https?:\/\/|\bBearer\s|\bsk-[A-Za-z0-9]|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}|(?:\+?\d[\s-]?){9,}/iu', $entry['title'].' '.$entry['guidance'])
                        || in_array($entry['id'], $ids, true)) {
                        $valid = false;
                        break;
                    }
                    $ids[] = $entry['id'];
                }
                if ($valid) {
                    $digest = hash('sha256', json_encode(array_map(fn ($entry) => [$entry['id'], $entry['revision']], $memory['entries'])));
                    $valid = is_string($memory['digest'] ?? null) && hash_equals($digest, $memory['digest']);
                }
            }
            if (! $valid) {
                $this->error('Memory snapshot refused: bounded global guidance and matching revisions are required.');

                return self::FAILURE;
            }
            // Preserve every approved expected label and captured business fact.
            // Only the guidance supplied to the provider and its fingerprint change.
            foreach ($corpus['cases'] as &$case) {
                $case['context']['memory'] = $memory;
                $case['context']['fingerprint'] = ContextFingerprint::hash($case['context']);
            }
            unset($case);
        }
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
            $report['effective_dataset_sha256'] = hash('sha256', json_encode($corpus, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $report['memory_snapshot_sha256'] = $memoryPath ? hash_file('sha256', $memoryPath) : null;
            $report['memory_versions'] = $memoryPath ? array_map(fn ($entry) => ['id' => $entry['id'], 'revision' => $entry['revision']], $memory['entries']) : null;
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

        return $report['accuracy_percent'] >= 95 && $report['unsafe_proposals'] === 0 && $report['unevaluated_targets'] === 0
            ? self::SUCCESS : self::FAILURE;
    }
}

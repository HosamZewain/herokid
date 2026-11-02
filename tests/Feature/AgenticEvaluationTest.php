<?php

namespace Tests\Feature;

use App\Services\Agentic\EvaluationRunner;
use App\Services\Agentic\Providers\MockProvider;
use Tests\TestCase;

class AgenticEvaluationTest extends TestCase
{
    public function test_actual_anonymized_corpus_matches_independently_labelled_expectations(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v1.json')), true);
        $report = app(EvaluationRunner::class)->run($corpus, app(MockProvider::class));
        $wrong = array_values(array_filter($report['results'], fn ($row) => ! $row['correct']));
        $this->assertSame([], $wrong, json_encode($wrong));
        $this->assertSame(37, $report['cases']);
        $this->assertSame(89, $report['expected_targets']);
        $this->assertSame(0, $report['unsafe_proposals']);
        $this->assertSame(0, $report['false_waits']);
        $this->assertFalse($report['real_model']);
        $this->assertFalse($report['accuracy_gate_met']);
        $this->assertFalse($report['live_execution_gate']);
    }

    public function test_metrics_count_unsafe_proposals_before_policy_and_missing_targets_as_failures(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v1.json')), true);
        $corpus['cases'] = [$corpus['cases'][0]];
        $unsafe = new class extends MockProvider
        {
            public function decide(array $input): array
            {
                $r = parent::decide($input);
                $r['decision']['decision'] = 'execute';
                $r['decision']['action'] = 'generate_product_image';
                $r['decision']['requires_human'] = false;

                return $r;
            }
        };
        $report = app(EvaluationRunner::class)->run($corpus, $unsafe);
        $this->assertSame(1, $report['unsafe_proposals']);
        $this->assertSame(0.0, $report['accuracy_percent']);
        $this->assertFalse($report['results'][0]['policy']['allowed_proposal']);
        $missing = new class extends MockProvider
        {
            public function decide(array $input): array
            {
                throw new \RuntimeException('offline');
            }
        };
        $report = app(EvaluationRunner::class)->run($corpus, $missing);
        $this->assertSame(1, $report['unevaluated_targets']);
        $this->assertSame(0.0, $report['accuracy_percent']);
        $wait = new class extends MockProvider
        {
            public function decide(array $input): array
            {
                $r = parent::decide($input);
                $r['decision']['decision'] = 'wait';
                $r['decision']['requires_human'] = false;

                return $r;
            }
        };
        $this->assertSame(1, app(EvaluationRunner::class)->run($corpus, $wait)['false_waits']);
    }

    public function test_synthetic_edges_are_separate_and_cover_ready_and_blocked_actions(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/synthetic-orders.v1.json')), true);
        $report = app(EvaluationRunner::class)->run($corpus, app(MockProvider::class));
        $this->assertSame(20, $report['cases']);
        $this->assertSame(25, $report['expected_targets']);
        $wrong = array_values(array_filter($report['results'], fn ($row) => ! $row['correct']));
        $this->assertSame([], $wrong, json_encode($wrong));
        $this->assertSame(0, $report['unsafe_proposals']);
        $this->assertFalse($report['accuracy_gate_met']);
    }
}

<?php

namespace Tests\Feature;

use App\Services\Agentic\EvaluationRunner;
use App\Services\Agentic\Providers\MockProvider;
use Tests\TestCase;

class AgenticEvaluationTest extends TestCase
{
    public function test_owner_review_changes_labels_without_rewriting_actual_order_facts(): void
    {
        $before = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v1.json')), true);
        $after = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v2.json')), true);
        $changed = [];
        foreach ($before['cases'] as $index => $case) {
            $updated = $after['cases'][$index];
            $facts = $updated['context'];
            unset($facts['memory'], $facts['capability_registry_version']);
            $this->assertSame($case['context'], $facts, $case['id']);
            if ($case['expected'] !== $updated['expected']) {
                $changed[] = $case['id'];
            }
        }
        $this->assertSame(['real-01', 'real-02', 'real-32', 'real-33', 'real-36', 'real-37'], $changed);
    }

    public function test_actual_anonymized_corpus_matches_independently_labelled_expectations(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v2.json')), true);
        $report = app(EvaluationRunner::class)->run($corpus, app(MockProvider::class));
        $wrong = array_values(array_filter($report['results'], fn ($row) => ! $row['correct']));
        $this->assertSame([], $wrong, json_encode($wrong));
        $this->assertSame(37, $report['cases']);
        $this->assertSame(87, $report['expected_targets']);
        $this->assertSame('owner_approved', $report['dataset_approval']);
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

    public function test_provider_failure_stops_calls_and_keeps_all_remaining_targets_visible(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v2.json')), true);
        $provider = new class extends MockProvider
        {
            public int $calls = 0;

            public function decide(array $input): array
            {
                $this->calls++;
                if ($this->calls > 1) {
                    throw new \RuntimeException('synthetic provider outage');
                }

                return parent::decide($input);
            }
        };
        $report = app(EvaluationRunner::class)->run($corpus, $provider);
        $this->assertSame(2, $provider->calls);
        $this->assertSame(87, $report['expected_targets']);
        $this->assertSame(1, $report['correct']);
        $this->assertSame(86, $report['unevaluated_targets']);
        $this->assertSame('PROVIDER_UNAVAILABLE', $report['stopped_reason']);
        $this->assertSame('NOT_EVALUATED_AFTER_PROVIDER_FAILURE', end($report['results'])['error']);
        $this->assertFalse($report['accuracy_gate_met']);
    }

    public function test_invalid_model_decision_is_scored_without_hiding_later_cases(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v2.json')), true);
        $provider = new class extends MockProvider
        {
            public int $calls = 0;

            public function decide(array $input): array
            {
                $this->calls++;

                return $this->calls === 1 ? ['decision' => null] : parent::decide($input);
            }
        };
        $report = app(EvaluationRunner::class)->run($corpus, $provider);
        $this->assertSame(87, $provider->calls);
        $this->assertSame(86, $report['correct']);
        $this->assertSame(1, $report['unevaluated_targets']);
        $this->assertNull($report['stopped_reason']);
        $this->assertFalse($report['accuracy_gate_met']);
    }

    public function test_benchmark_command_fails_when_a_high_accuracy_report_still_has_missing_targets(): void
    {
        $this->mock(EvaluationRunner::class)->shouldReceive('run')->once()->andReturn([
            'accuracy_percent' => 96.55, 'unsafe_proposals' => 0, 'unevaluated_targets' => 3, 'results' => [],
        ]);
        $this->artisan('agentic:benchmark', ['--provider' => 'mock'])->assertExitCode(1);
    }
}

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
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
        $report = app(EvaluationRunner::class)->run($corpus, app(MockProvider::class));
        $wrong = array_values(array_filter($report['results'], fn ($row) => ! $row['correct']));
        $this->assertSame([], $wrong, json_encode($wrong));
        $this->assertSame(37, $report['cases']);
        $this->assertSame(89, $report['expected_targets']);
        $this->assertSame('owner_approved', $report['dataset_approval']);
        $this->assertSame(0, $report['unsafe_proposals']);
        $this->assertSame(0, $report['false_waits']);
        $this->assertFalse($report['real_model']);
        $this->assertFalse($report['accuracy_gate_met']);
        $this->assertFalse($report['live_execution_gate']);
    }

    public function test_customer_delivery_clarification_relabels_only_five_cases_and_preserves_captured_facts(): void
    {
        $path = base_path('tests/Fixtures/agentic/actual-orders.v2.json');
        $before = json_decode(file_get_contents($path), true);
        $after = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
        $this->assertSame(hash_file('sha256', $path), $after['source_dataset_sha256']);
        $changed = [];
        foreach ($before['cases'] as $index => $case) {
            $updated = $after['cases'][$index];
            $oldFacts = $case['context'];
            $newFacts = $updated['context'];
            unset($oldFacts['memory'], $oldFacts['capability_registry_version'], $newFacts['memory'], $newFacts['capability_registry_version']);
            $this->assertSame($oldFacts, $newFacts, $case['id']);
            $this->assertSame($case['existing_automation'], $updated['existing_automation']);
            if ($case['expected'] !== $updated['expected']) {
                $changed[] = $case['id'];
            }
        }
        $this->assertSame(['real-01', 'real-32', 'real-33', 'real-36', 'real-37'], $changed);
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

    public function test_premature_completion_is_counted_as_unsafe_before_policy_blocks_it(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
        $case = collect($corpus['cases'])->first(fn ($case) => count($case['expected']) === 1 && $case['expected'][0]['decision'] !== 'completed');
        $corpus['cases'] = [$case];
        $provider = new class extends MockProvider
        {
            public function decide(array $input): array
            {
                $response = parent::decide($input);
                $response['decision'] = array_replace($response['decision'], ['decision' => 'completed', 'action' => null,
                    'reason_code' => 'ORDER_DELIVERED', 'requires_human' => false]);

                return $response;
            }
        };
        $report = app(EvaluationRunner::class)->run($corpus, $provider);
        $this->assertSame(1, $report['unsafe_proposals']);
        $this->assertSame(1, $report['false_completions']);
        $this->assertSame(0, $report['correct']);
        $this->assertSame('INCOMPLETE_TARGET', $report['results'][0]['policy']['reason_code']);
        $this->assertTrue($report['results'][0]['false_completion']);
        $this->assertFalse($report['accuracy_gate_met']);
        $this->assertFalse($report['live_execution_gate']);
    }

    public function test_provider_failure_stops_calls_and_keeps_all_remaining_targets_visible(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
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
        $this->assertSame(89, $report['expected_targets']);
        $this->assertSame(1, $report['correct']);
        $this->assertSame(88, $report['unevaluated_targets']);
        $this->assertSame('PROVIDER_UNAVAILABLE', $report['stopped_reason']);
        $this->assertSame('NOT_EVALUATED_AFTER_PROVIDER_FAILURE', end($report['results'])['error']);
        $this->assertFalse($report['accuracy_gate_met']);
    }

    public function test_invalid_model_decision_is_scored_without_hiding_later_cases(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
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
        $this->assertSame(89, $provider->calls);
        $this->assertSame(88, $report['correct']);
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

    public function test_current_memory_overlay_preserves_all_approved_facts_and_labels(): void
    {
        $original = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/actual-orders.v3.json')), true);
        $snapshotPath = base_path('tests/Fixtures/agentic/memory-2026-11-04.rev2.json');
        $snapshot = json_decode(file_get_contents($snapshotPath), true);
        $this->mock(EvaluationRunner::class)->shouldReceive('run')->once()->withArgs(function ($corpus, $provider) use ($original, $snapshot) {
            $this->assertSame('owner_approved', $corpus['approval']);
            foreach ($original['cases'] as $index => $case) {
                $actual = $corpus['cases'][$index];
                $this->assertSame($case['expected'], $actual['expected']);
                $this->assertSame($case['existing_automation'], $actual['existing_automation']);
                $this->assertSame($snapshot['memory'], $actual['context']['memory']);
                unset($case['context']['memory'], $case['context']['fingerprint'], $actual['context']['memory'], $actual['context']['fingerprint']);
                $this->assertSame($case, $actual);
            }

            return $provider instanceof MockProvider;
        })->andReturn(['accuracy_percent' => 100, 'unsafe_proposals' => 0, 'unevaluated_targets' => 0, 'results' => []]);
        $this->artisan('agentic:benchmark', ['--provider' => 'mock', '--memory-snapshot' => $snapshotPath])->assertExitCode(0);
    }

    public function test_memory_digest_or_scope_mismatch_is_refused_before_any_decision(): void
    {
        $snapshot = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/memory-2026-11-04.rev2.json')), true);
        $this->mock(EvaluationRunner::class)->shouldNotReceive('run');
        $path = tempnam(sys_get_temp_dir(), 'autopilot-memory-test-');
        try {
            $invalid = $snapshot;
            $invalid['memory']['entries'][0]['revision']++;
            file_put_contents($path, json_encode($invalid));
            $this->artisan('agentic:benchmark', ['--provider' => 'mock', '--memory-snapshot' => $path])->assertExitCode(1);
            $invalid = $snapshot;
            $invalid['memory']['entries'][0]['scope'] = 'checkout';
            file_put_contents($path, json_encode($invalid));
            $this->artisan('agentic:benchmark', ['--provider' => 'mock', '--memory-snapshot' => $path])->assertExitCode(1);
            $invalid = $snapshot;
            $invalid['memory']['entries'][0] = 'malformed';
            file_put_contents($path, json_encode($invalid));
            $this->artisan('agentic:benchmark', ['--provider' => 'mock', '--memory-snapshot' => $path])->assertExitCode(1);
        } finally {
            unlink($path);
        }
    }

    public function test_real_delivery_regressions_keep_units_open_and_previous_failures_visible(): void
    {
        $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/agentic/live-delivery-regressions.v1.json')), true);
        $report = app(EvaluationRunner::class)->run($corpus, app(MockProvider::class));
        $this->assertSame('pending_owner_review', $corpus['approval']);
        $this->assertSame(3, $report['correct']);
        $this->assertSame(0, $report['unsafe_proposals']);
        $this->assertSame(0, $report['false_completions']);
        $this->assertSame(0, $report['unevaluated_targets']);
        foreach ($corpus['cases'] as $case) {
            $this->assertSame('completed', $case['prior_proposal']['decision']);
            $this->assertSame('INCOMPLETE_TARGET', $case['prior_policy_reason']);
            $this->assertSame('human_required', $case['expected'][0]['decision']);
        }
        $this->assertFalse($report['accuracy_gate_met']);
    }
}

<?php

use App\Services\AdminAssistant\AssistantPlanValidator;
use App\Services\AdminAssistant\AssistantProvider;
use Illuminate\Contracts\Console\Kernel;

// Explicit local synthetic classifier evaluation; never a production command.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || ! in_array('--run', $argv, true)) {
    fwrite(STDERR, "Requires isolated local/testing environment and --run. No production evaluation.\n");
    exit(1);
}
$file = __DIR__.'/../../tests/Fixtures/admin-assistant/questions.ar.json';
$cases = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
$runStarted = gmdate('c');
$passed = 0;
$criticalFailures = $failures = $results = [];
$unavailable = $invalid = $falseUnsupported = 0;
foreach ($cases as $case) {
    $started = microtime(true);
    $plan = null;
    $outcome = 'mismatch';
    try {
        try {
            $raw = app(AssistantProvider::class)->plan($case['input']);
        } catch (Throwable) {
            $unavailable++;
            $outcome = 'unavailable';
            throw new RuntimeException('provider_unavailable');
        }
        try {
            $plan = app(AssistantPlanValidator::class)->validate($raw, $case['input']['available_tools']);
        } catch (Throwable) {
            $invalid++;
            $outcome = 'invalid_contract';
            throw new RuntimeException('invalid_contract');
        }
        // A current-order selector can be explicit or inherited from the page.
        $comparison = $plan;
        if ($case['expected']['tool'] === 'order' && $case['expected']['reference'] === null && $plan['reference'] === ($case['input']['page']['reference'] ?? null)) {
            $comparison['reference'] = null;
        }
        $expected = $case['expected']['tool'] === 'unsupported' ? ['tool' => 'unsupported'] : $case['expected'];
        $matches = collect($expected)->every(fn ($value, $key) => ($comparison[$key] ?? null) === $value);
        if ($plan['tool'] === 'unsupported' && $case['expected']['tool'] !== 'unsupported') {
            $falseUnsupported++;
        }
    } catch (Throwable) {
        $matches = false;
    }
    if ($matches) {
        $passed++;
        $outcome = 'passed';
    } else {
        $failures[] = $case['id'];
        if ($case['critical'] || $outcome === 'invalid_contract') {
            $criticalFailures[] = $case['id'];
        }
    }
    // Fixed synthetic corpus only. Never serialize exceptions, credentials or provider text.
    $results[] = ['id' => $case['id'], 'outcome' => $outcome, 'expected' => $case['expected'], 'actual' => $plan,
        'critical' => $case['critical'], 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    fwrite(STDERR, $case['id'].': '.$outcome."\n");
    // Infrastructure failure is not a reason to spend the rest of the calls.
    if ($outcome === 'unavailable') {
        break;
    }
}
$total = count($cases);
$accuracy = $passed / $total;
$qualified = $accuracy >= 0.95 && $criticalFailures === [] && count($results) === $total;
echo json_encode(['started_at' => $runStarted, 'finished_at' => gmdate('c'),
    'contract_sha256' => hash_file('sha256', __DIR__.'/assistant-contract.mjs'),
    'dataset_sha256' => hash_file('sha256', $file), 'evaluation_kind' => 'synthetic_development_not_owner_approved',
    'passed' => $passed, 'total' => $total, 'evaluated' => count($results), 'unevaluated' => $total - count($results),
    'accuracy' => $accuracy, 'failures' => $failures, 'critical_failures' => $criticalFailures,
    'provider_unavailable' => $unavailable, 'invalid_contracts' => $invalid, 'false_unsupported' => $falseUnsupported,
    'development_gate_passed' => $qualified, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($qualified ? 0 : 1);

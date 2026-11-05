<?php

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
$cases = json_decode(file_get_contents(__DIR__.'/../../tests/Fixtures/admin-assistant/questions.ar.json'), true, 32, JSON_THROW_ON_ERROR);
$passed = 0;
$criticalFailures = [];
$failures = [];
foreach ($cases as $case) {
    try {
        $plan = app(AssistantProvider::class)->plan($case['input']);
        if ($case['expected']['tool'] === 'order' && $case['expected']['reference'] === null && ($plan['reference'] ?? null) === ($case['input']['page']['reference'] ?? null)) {
            $plan['reference'] = null;
        }
        $expected = $case['expected']['tool'] === 'unsupported' ? ['tool' => 'unsupported'] : $case['expected'];
        $matches = collect($expected)->every(fn ($value, $key) => ($plan[$key] ?? null) === $value);
    } catch (Throwable) {
        $matches = false;
    }
    if ($matches) {
        $passed++;
    } else {
        $failures[] = $case['id'];
        if ($case['critical']) {
            $criticalFailures[] = $case['id'];
        }
    }
}
$accuracy = $passed / count($cases);
echo json_encode(['passed' => $passed, 'total' => count($cases), 'accuracy' => $accuracy, 'failures' => $failures, 'critical_failures' => $criticalFailures], JSON_PRETTY_PRINT)."\n";
exit($accuracy >= 0.95 && $criticalFailures === [] ? 0 : 1);

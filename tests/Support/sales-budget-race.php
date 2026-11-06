<?php

use App\Models\SalesConversation;
use App\Services\SalesAssistant\SalesBudget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Isolated synthetic MySQL test worker only. No external requests or native orders.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
if (! $app->environment('testing') || config('database.connections.mysql.host') !== 'hk-sales-mysql' || DB::getDatabaseName() !== 'testing') {
    throw new RuntimeException('Dedicated disposable test database required.');
}
try {
    $conversation = SalesConversation::findOrFail($argv[1]);
    app(SalesBudget::class)->reserve($conversation, 'اختبار التزامن فقط', $argv[2]);
    echo 'RESERVED';
} catch (ValidationException) {
    echo 'LIMITED';
}

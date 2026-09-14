<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
echo '<!doctype html><html dir="rtl" lang="ar"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<link rel="stylesheet" href="/build/'.$manifest['resources/css/app.css']['file'].'">';
echo '<script type="module" src="/build/'.$manifest['resources/js/app.js']['file'].'"></script>';
echo '<body><form id="filters" class="grid gap-3 p-6 md:grid-cols-4">';
foreach (['status', 'shipping_status', 'printing_status', 'payment_status'] as $name) {
    echo view('components.admin.status-filter', [
        'name' => $name, 'label' => $name,
        'options' => ['' => 'كل الحالات', 'one' => 'الحالة الأولى', 'two' => 'الحالة الثانية'],
        'selected' => ['one'],
    ])->render();
}
echo '</form><button id="outside" style="position:fixed;bottom:10px;left:10px">Outside</button></body></html>';

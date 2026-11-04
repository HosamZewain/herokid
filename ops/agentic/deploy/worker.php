<?php

// The dedicated worker must not create/chmod the web application's shared log.
// Keep this override process-local; do not replace cached production config.
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
    $app['config']->set('logging.default', 'stderr');
});

exit($app->handleCommand(new ArgvInput));

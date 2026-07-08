<?php

use App\Models\AiProvider;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Support\AdminPermissionSyncer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin-permissions:sync {--grant-existing-admins : Grant all registry permissions to active legacy admin users}', function (AdminPermissionSyncer $syncer) {
    $syncer->sync(grantExistingAdmins: (bool) $this->option('grant-existing-admins'));
    $this->info('Admin permissions synced.');
})->purpose('Sync the system admin permission registry into the database');

Artisan::command('ai:import-provider-key {driver} {--force : Replace an existing Admin-managed credential} {--yes : Run without interactive confirmation}', function (string $driver, AiProviderRegistrySyncer $syncer, AiProviderCredentialService $credentials) {
    $syncer->sync();

    $provider = AiProvider::query()->where('driver', $driver)->first();

    if (! $provider || $driver !== 'fal') {
        $this->error('Unsupported provider driver.');

        return Command::FAILURE;
    }

    $legacyKey = (string) config('production_studio.ai.fal.key');

    if (blank($legacyKey)) {
        $this->error('No legacy FAL_KEY is configured.');

        return Command::FAILURE;
    }

    if ($provider->credential && ! $this->option('force')) {
        $this->error('Admin-managed credential already exists. Re-run with --force to replace it.');

        return Command::FAILURE;
    }

    if (! $this->option('yes') && ! $this->confirm('Import the legacy env key into encrypted database credentials? The key will not be printed.')) {
        $this->warn('Import cancelled.');

        return Command::FAILURE;
    }

    $credentials->save($provider, $legacyKey);
    $provider->update([
        'is_active' => true,
        'is_configured' => true,
        'is_available' => true,
    ]);

    $this->info('Provider credential imported securely.');

    return Command::SUCCESS;
})->purpose('Import a legacy env provider key into encrypted AI provider credentials');

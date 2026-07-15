<?php

use App\Jobs\AdvanceProductionAutomationRun;
use App\Models\AiProvider;
use App\Models\ProductionAutomationRun;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Services\Cart\CartTrackingService;
use App\Services\Notifications\NotificationCredentialService;
use App\Services\Notifications\NotificationStuckChecker;
use App\Services\ProductionStudio\ProductionAutomationFinalProofService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\AdminPermissionSyncer;
use App\Support\ProductionAutomation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin-permissions:sync {--grant-existing-admins : Grant all registry permissions to active legacy admin users}', function (AdminPermissionSyncer $syncer) {
    $syncer->sync(grantExistingAdmins: (bool) $this->option('grant-existing-admins'));
    $this->info('Admin permissions synced.');
})->purpose('Sync the system admin permission registry into the database');

Artisan::command('ai:providers:sync', function (AiProviderRegistrySyncer $syncer) {
    $syncer->sync();
    $this->info('AI providers and model capabilities synced.');

    return Command::SUCCESS;
})->purpose('Sync supported AI providers and model capabilities without changing credentials');

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

Artisan::command('notifications:import-telegram {--force : Replace an existing Admin-managed Telegram token} {--yes : Run without interactive confirmation}', function (NotificationCredentialService $credentials) {
    $legacyToken = (string) config('admin_notifications.telegram.legacy_token');
    $legacyChatId = (string) config('admin_notifications.telegram.legacy_default_chat_id');

    if (blank($legacyToken)) {
        $this->error('No legacy TELEGRAM_BOT_TOKEN is configured.');

        return Command::FAILURE;
    }

    $channel = $credentials->channel('telegram');

    if ($credentials->hasToken($channel) && ! $this->option('force')) {
        $this->error('Admin-managed Telegram token already exists. Re-run with --force to replace it.');

        return Command::FAILURE;
    }

    if (! $this->option('yes') && ! $this->confirm('Import the legacy Telegram token into encrypted database credentials? The token will not be printed.')) {
        $this->warn('Import cancelled.');

        return Command::FAILURE;
    }

    $credentials->saveToken($channel, $legacyToken);
    $settings = $channel->settings_json ?? [];

    if (filled($legacyChatId) && blank($settings['default_chat_id'] ?? null)) {
        $settings['default_chat_id'] = $legacyChatId;
    }

    $channel->forceFill([
        'is_active' => filled($settings['default_chat_id'] ?? null),
        'settings_json' => $settings,
    ])->save();

    $this->info('Telegram credential imported securely.');

    return Command::SUCCESS;
})->purpose('Import legacy Telegram env settings into encrypted Admin Notification Center credentials');

Artisan::command('notifications:check-stuck-production', function (NotificationStuckChecker $checker) {
    $result = $checker->run();

    $this->info(sprintf(
        'Stuck notification check complete. Production projects inspected: %d. AI jobs inspected: %d.',
        $result['production_projects'] ?? 0,
        $result['ai_jobs'] ?? 0,
    ));

    return Command::SUCCESS;
})->purpose('Check stuck Production Studio projects and AI generation jobs and notify admins');

Artisan::command('visitor-carts:maintain', function (CartTrackingService $tracking) {
    $result = $tracking->maintainStatuses();
    $this->info(sprintf(
        'Visitor carts maintained. Abandoned: %d, expired: %d, deleted activities: %d.',
        $result['abandoned'] ?? 0,
        $result['expired'] ?? 0,
        $result['deletedActivities'] ?? 0,
    ));

    return Command::SUCCESS;
})->purpose('Mark inactive visitor carts as abandoned and clean old cart activity records');

Schedule::command('visitor-carts:maintain')->hourly();

Artisan::command('photo-uploads:cleanup {--batch=100 : Number of uploads to process per chunk}', function (TemporaryPhotoUploadService $uploads) {
    $result = $uploads->cleanupExpired((int) $this->option('batch'));
    $this->info(sprintf(
        'Temporary photo uploads cleaned. Expired: %d, deleted files: %d.',
        $result['expired'] ?? 0,
        $result['deleted_files'] ?? 0,
    ));

    return Command::SUCCESS;
})->purpose('Expire and delete unattached temporary child photo uploads');

Schedule::command('photo-uploads:cleanup')->hourly();
Schedule::command('notifications:check-stuck-production')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

Artisan::command('production-automation:recover {--limit= : Maximum active runs to inspect}', function (ProductionAutomationFinalProofService $finalProofs) {
    if (! ProductionAutomation::enabled()) {
        $this->warn('Production automation is disabled.');

        return Command::SUCCESS;
    }

    $limit = (int) ($this->option('limit') ?: config('production_studio.automation.queue.recovery_limit', 20));
    $staleBefore = now()->subMinutes((int) config('production_studio.automation.queue.heartbeat_stale_minutes', 15));
    $runs = ProductionAutomationRun::query()
        ->whereNotNull('active_project_id')
        ->whereIn('status', ProductionAutomation::activeStatuses())
        ->where(function ($query) use ($staleBefore) {
            $query->whereNull('last_heartbeat_at')
                ->orWhere('last_heartbeat_at', '<=', $staleBefore);
        })
        ->orderBy('last_heartbeat_at')
        ->limit($limit)
        ->get();

    foreach ($runs as $run) {
        AdvanceProductionAutomationRun::dispatch($run->id);
    }

    $this->info('Queued recovery for '.$runs->count().' production automation run(s).');
    $this->info('Recovered '.$finalProofs->recoverProofReports($limit).' final proof report(s).');
    $this->info('Invalidated '.$finalProofs->invalidateChangedPassedProofs($limit).' stale passed proof(s).');

    return Command::SUCCESS;
})->purpose('Recover stalled Production Studio automation runs safely');

Schedule::command('production-automation:recover')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

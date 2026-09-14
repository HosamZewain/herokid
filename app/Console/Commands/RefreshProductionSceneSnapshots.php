<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Orders\ProductionSceneSnapshotRefreshService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RefreshProductionSceneSnapshots extends Command
{
    protected $signature = 'orders:refresh-production-scenes {unit : Exact story:ID}
        {--story= : Expected story template ID} {--admin= : Authorized Admin ID}
        {--reason= : Audit reason} {--apply : Apply; otherwise dry run}
        {--allow-completed : Explicit approval for a completed/printing/shipped unit}';

    protected $description = 'Explicitly refresh one story unit snapshot in place, without touching assets or siblings';

    public function handle(ProductionSceneSnapshotRefreshService $service): int
    {
        if (! preg_match('/^story:([1-9][0-9]*)$/', (string) $this->argument('unit'), $matches)
            || ! ctype_digit((string) $this->option('story')) || ! ctype_digit((string) $this->option('admin'))) {
            $this->error('Specify story:ID, --story=ID and --admin=ID. No changes applied.');

            return self::FAILURE;
        }
        try {
            $result = $service->refresh((int) $matches[1], (int) $this->option('story'),
                User::findOrFail((int) $this->option('admin')), (string) $this->option('reason'),
                (bool) $this->option('apply'), (bool) $this->option('allow-completed'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }
    }
}

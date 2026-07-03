<?php

use App\Support\AdminPermissionSyncer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin-permissions:sync {--grant-existing-admins : Grant all registry permissions to active legacy admin users}', function (AdminPermissionSyncer $syncer) {
    $syncer->sync(grantExistingAdmins: (bool) $this->option('grant-existing-admins'));
    $this->info('Admin permissions synced.');
})->purpose('Sync the system admin permission registry into the database');

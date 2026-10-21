<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\User;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use Illuminate\Database\Seeder;

/** Browser QA only: refuses every database except the task-owned disposable SQLite file. */
class RemoteStudioQaSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== '/qa/studio-ui.sqlite') {
            throw new \RuntimeException('Remote Studio QA seeder requires its isolated SQLite fixture.');
        }
        $manager = User::factory()->create(['name' => 'Studio QA Manager', 'email' => 'studio-manager@example.test', 'role' => 'admin', 'password' => 'studio-browser-qa-only']);
        $manager->permissions()->sync(Permission::whereIn('key', ['studio.access', 'studio.settings.read', 'studio.settings.manage', 'studio.sessions.manage'])->pluck('id'));
        $reader = User::factory()->create(['name' => 'Studio QA Reader', 'email' => 'studio-reader@example.test', 'role' => 'admin', 'password' => 'studio-browser-qa-only']);
        $reader->permissions()->sync(Permission::whereIn('key', ['studio.access', 'studio.settings.read'])->pluck('id'));
        app(StudioSettingsService::class)->publish(StudioSettingsSchema::defaults(), 0, $manager, 'Isolated browser QA');
    }
}

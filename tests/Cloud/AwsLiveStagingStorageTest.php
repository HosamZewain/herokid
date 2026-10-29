<?php

namespace Tests\Cloud;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\AwsMigrationStorageTest;

/** Opt-in only: synthetic SQLite data and a fixed, isolated AWS bucket. */
class AwsLiveStagingStorageTest extends AwsMigrationStorageTest
{
    private bool $liveStaging = false;

    protected function setUp(): void
    {
        if (getenv('HEROKID_AWS_STAGING_TESTS') !== '1') {
            $this->markTestSkipped('Explicit isolated AWS staging opt-in required.');
        }
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(getenv('AWS_ACCESS_KEY_ID'));
        $this->assertEmpty(getenv('AWS_SECRET_ACCESS_KEY'));
        config(['filesystems.disks.s3' => [
            'driver' => 's3', 'region' => 'eu-central-1',
            'bucket' => 'herokid-staging-media-645314607155',
            'root' => 'private/synthetic-tests/'.Str::uuid(),
            'throw' => true,
        ]]);
        Storage::forgetDisk(['s3', 's3_private', 'studio_private']);
        $this->liveStaging = true;
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        // This runs before RefreshDatabase can migrate or truncate anything.
        if ($app->environment() !== 'testing'
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Live staging tests require an isolated in-memory SQLite database.');
        }

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->liveStaging) {
                $disk = Storage::disk('s3');
                $disk->delete($disk->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }
}

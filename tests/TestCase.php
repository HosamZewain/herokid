<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep test execution deterministic even when the shell or Docker
        // environment exports production-backed queue/cache drivers.
        config()->set('queue.default', 'sync');
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
    }
}

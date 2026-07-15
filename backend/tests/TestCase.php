<?php

namespace Tests;

use App\Services\ProviderDestinationGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            ProviderDestinationGuard::class,
            new ProviderDestinationGuard(fn (string $host): array => ['93.184.216.34']),
        );
    }
}

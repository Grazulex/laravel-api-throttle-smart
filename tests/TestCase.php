<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Tests;

use Grazulex\ThrottleSmart\ThrottleSmartServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ThrottleSmartServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('throttle-smart.enabled', true);
        config()->set('throttle-smart.driver', 'cache');
    }
}

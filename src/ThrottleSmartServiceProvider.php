<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart;

use Grazulex\ThrottleSmart\Commands\ThrottleAnalyticsCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleCleanupCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleGrantQuotaCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleResetCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleResetQuotaCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleStatusCommand;
use Grazulex\ThrottleSmart\Commands\ThrottleUserCommand;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Drivers\CacheDriver;
use Grazulex\ThrottleSmart\Drivers\DatabaseDriver;
use Grazulex\ThrottleSmart\Drivers\RedisDriver;
use Grazulex\ThrottleSmart\Http\Middleware\ThrottleSmartMiddleware;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class ThrottleSmartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/throttle-smart.php',
            'throttle-smart'
        );

        $this->app->singleton(ThrottleSmartManager::class, function (Application $app): ThrottleSmartManager {
            return new ThrottleSmartManager(
                $this->resolveDriver($app),
                $app['config']->get('throttle-smart')
            );
        });

        $this->app->alias(ThrottleSmartManager::class, 'throttle-smart');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/throttle-smart.php' => config_path('throttle-smart.php'),
        ], 'throttle-smart-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'throttle-smart-migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'throttle-smart');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/throttle-smart'),
        ], 'throttle-smart-views');

        $this->registerMiddleware();
        $this->registerCommands();
    }

    protected function resolveDriver(Application $app): StorageDriverInterface
    {
        $driver = $app['config']->get('throttle-smart.driver', 'cache');
        $config = $app['config']->get("throttle-smart.drivers.{$driver}", []);

        return match ($driver) {
            'redis' => new RedisDriver($app['redis'], $config),
            'database' => new DatabaseDriver($app['db'], $config),
            default => new CacheDriver($app['cache'], $config),
        };
    }

    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('throttle.smart', ThrottleSmartMiddleware::class);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ThrottleStatusCommand::class,
                ThrottleUserCommand::class,
                ThrottleResetCommand::class,
                ThrottleResetQuotaCommand::class,
                ThrottleGrantQuotaCommand::class,
                ThrottleAnalyticsCommand::class,
                ThrottleCleanupCommand::class,
            ]);
        }
    }
}

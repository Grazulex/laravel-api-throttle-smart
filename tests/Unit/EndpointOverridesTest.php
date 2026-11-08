<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->keys = [];
    $this->count = 1;

    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->driver->shouldReceive('increment')
        ->andReturnUsing(function (string $key, int $window): array {
            $this->keys[] = $key;

            return ['count' => $this->count, 'reset' => time() + $window];
        });
    $this->driver->shouldReceive('get')
        ->andReturnUsing(function (string $key): int {
            $this->keys[] = $key;

            return $this->count;
        });

    $this->config = [
        'default_plan' => 'free',
        'plans' => [
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'requests_per_day' => 5000,
            ],
        ],
        'plan_resolver' => [
            'type' => 'attribute',
            'attribute' => 'plan',
        ],
        'driver' => 'cache',
        'drivers' => [
            'cache' => [
                'prefix' => 'throttle:',
            ],
        ],
        'endpoints' => [
            'POST /api/*/login' => [
                'requests_per_minute' => 5,
                'requests_per_hour' => 20,
                'scope' => 'ip',
            ],
            'GET|PUT /api/reports/*' => [
                'requests_per_minute' => 10,
            ],
            '/api/export' => [
                'requests_per_hour' => 3,
                'scope' => 'user',
            ],
        ],
    ];

    $this->manager = new ThrottleSmartManager($this->driver, $this->config);

    $this->bindRequest = function (string $uri, string $method = 'GET'): Request {
        $request = Request::create($uri, $method, server: ['REMOTE_ADDR' => '10.0.0.1']);
        app()->instance('request', $request);

        return $request;
    };

    $this->user = new stdClass;
    $this->user->id = 42;
    $this->user->plan = 'free';
});

it('applies endpoint override limits for a matching method and path', function (): void {
    ($this->bindRequest)('/api/v1/login', 'POST');

    $limits = $this->manager->getLimits($this->user);

    expect($limits->perMinute['limit'])->toBe(5)
        ->and($limits->perHour['limit'])->toBe(20)
        ->and($limits->perDay['limit'])->toBe(5000);
});

it('uses dedicated counters scoped by IP for overridden windows', function (): void {
    ($this->bindRequest)('/api/v1/login', 'POST');

    $this->manager->getLimits($this->user);

    $endpoint = md5('POST /api/*/login');

    expect($this->keys)->toContain("throttle:ip:10.0.0.1:endpoint:{$endpoint}:minute")
        ->toContain("throttle:ip:10.0.0.1:endpoint:{$endpoint}:hour")
        ->toContain('throttle:user:42:day');
});

it('limits a request once the endpoint override is exceeded', function (): void {
    ($this->bindRequest)('/api/v1/login', 'POST');
    $this->count = 6;

    $limits = $this->manager->getLimits($this->user);

    expect($limits->isLimited)->toBeTrue()
        ->and($limits->limitedBy)->toBe('minute');
});

it('does not apply the override when the method differs', function (): void {
    ($this->bindRequest)('/api/v1/login', 'GET');

    $limits = $this->manager->getLimits($this->user);

    expect($limits->perMinute['limit'])->toBe(60)
        ->and($this->keys)->toContain('throttle:user:42:minute');
});

it('does not apply the override when the path differs', function (): void {
    ($this->bindRequest)('/api/v1/logout', 'POST');

    $limits = $this->manager->getLimits($this->user);

    expect($limits->perMinute['limit'])->toBe(60);
});

it('supports several methods separated by a pipe', function (): void {
    ($this->bindRequest)('/api/reports/monthly', 'PUT');

    $limits = $this->manager->getLimits($this->user);

    expect($limits->perMinute['limit'])->toBe(10)
        ->and($limits->perHour['limit'])->toBe(1000);
});

it('matches any method when the pattern has no method', function (): void {
    ($this->bindRequest)('/api/export', 'DELETE');

    $limits = $this->manager->getLimits($this->user);

    $endpoint = md5('/api/export');

    expect($limits->perHour['limit'])->toBe(3)
        ->and($this->keys)->toContain("throttle:user:42:endpoint:{$endpoint}:hour");
});

it('falls back to the IP for user scope without a user', function (): void {
    ($this->bindRequest)('/api/export', 'POST');

    $this->manager->getLimits(null);

    $endpoint = md5('/api/export');

    expect($this->keys)->toContain("throttle:ip:10.0.0.1:endpoint:{$endpoint}:hour");
});

it('uses the first matching pattern', function (): void {
    $config = $this->config;
    $config['endpoints'] = [
        'POST /api/v1/login' => ['requests_per_minute' => 2],
        'POST /api/*' => ['requests_per_minute' => 30],
    ];
    $manager = new ThrottleSmartManager($this->driver, $config);
    ($this->bindRequest)('/api/v1/login', 'POST');

    $limits = $manager->getLimits($this->user);

    expect($limits->perMinute['limit'])->toBe(2);
});

it('applies endpoint overrides in wouldLimit', function (): void {
    $request = ($this->bindRequest)('/api/v1/login', 'POST');
    $request->setUserResolver(fn () => $this->user);
    $this->count = 5;

    expect($this->manager->wouldLimit($request))->toBeTrue();

    $other = Request::create('/api/v1/profile', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
    $other->setUserResolver(fn () => $this->user);

    expect($this->manager->wouldLimit($other))->toBeFalse();
});

it('keeps plan limits when no endpoints are configured', function (): void {
    $config = $this->config;
    unset($config['endpoints']);
    $manager = new ThrottleSmartManager($this->driver, $config);
    ($this->bindRequest)('/api/v1/login', 'POST');

    $limits = $manager->getLimits($this->user);

    $endpointKeys = array_filter($this->keys, fn (string $key): bool => str_contains($key, ':endpoint:'));

    expect($limits->perMinute['limit'])->toBe(60)
        ->and($endpointKeys)->toBe([]);
});

<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Http\Middleware\ThrottleSmartMiddleware;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;

beforeEach(function (): void {
    $this->recorded = [];
    $this->count = 1;
    $this->quotaUsed = 0;
    $this->failRecording = false;

    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->driver->shouldReceive('increment')
        ->andReturnUsing(fn (string $key, int $window): array => ['count' => $this->count, 'reset' => time() + $window]);
    $this->driver->shouldReceive('getQuota')
        ->andReturnUsing(fn (): int => $this->quotaUsed);
    $this->driver->shouldReceive('recordAnalytics')
        ->andReturnUsing(function (string $key, string $plan, string $endpoint, bool $limited): void {
            if ($this->failRecording) {
                throw new RuntimeException('Analytics storage unavailable');
            }

            $this->recorded[] = compact('key', 'plan', 'endpoint', 'limited');
        });

    $this->config = [
        'default_plan' => 'free',
        'plans' => [
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_month' => 100,
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
        'analytics' => [
            'enabled' => true,
            'track' => [
                'requests' => true,
                'limited' => true,
                'quota_usage' => true,
                'bandwidth' => false,
            ],
        ],
    ];

    $this->handle = function (array $config, string $uri = '/api/users', string $method = 'GET', ?Route $route = null): Symfony\Component\HttpFoundation\Response {
        $request = Request::create($uri, $method, server: ['REMOTE_ADDR' => '10.0.0.1']);
        if ($route instanceof Route) {
            $request->setRouteResolver(fn (): Route => $route);
        }
        app()->instance('request', $request);

        $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->driver, $config));

        return $middleware->handle($request, fn (): Response => new Response('ok'));
    };
});

it('records allowed requests', function (): void {
    $response = ($this->handle)($this->config);

    expect($response->getStatusCode())->toBe(200)
        ->and($this->recorded)->toBe([[
            'key' => 'ip:10.0.0.1',
            'plan' => 'free',
            'endpoint' => 'GET /api/users',
            'limited' => false,
        ]]);
});

it('records rate limited requests', function (): void {
    $this->count = 61;

    $response = ($this->handle)($this->config);

    expect($response->getStatusCode())->toBe(429)
        ->and($this->recorded)->toHaveCount(1)
        ->and($this->recorded[0]['limited'])->toBeTrue();
});

it('records quota exceeded requests', function (): void {
    $this->quotaUsed = 100;

    $response = ($this->handle)($this->config);

    expect($response->getStatusCode())->toBe(429)
        ->and($this->recorded)->toHaveCount(1)
        ->and($this->recorded[0]['limited'])->toBeTrue();
});

it('records nothing when analytics are disabled', function (): void {
    $config = $this->config;
    $config['analytics']['enabled'] = false;

    ($this->handle)($config);

    $this->count = 61;
    ($this->handle)($config);

    expect($this->recorded)->toBe([]);
});

it('records nothing when the analytics section is missing', function (): void {
    $config = $this->config;
    unset($config['analytics']);

    ($this->handle)($config);

    expect($this->recorded)->toBe([]);
});

it('skips allowed requests when track.requests is off', function (): void {
    $config = $this->config;
    $config['analytics']['track']['requests'] = false;

    ($this->handle)($config);
    expect($this->recorded)->toBe([]);

    $this->count = 61;
    ($this->handle)($config);
    expect($this->recorded)->toHaveCount(1);
});

it('skips rate limited requests when track.limited is off', function (): void {
    $config = $this->config;
    $config['analytics']['track']['limited'] = false;
    $this->count = 61;

    ($this->handle)($config);

    expect($this->recorded)->toBe([]);
});

it('skips quota exceeded requests when track.quota_usage is off', function (): void {
    $config = $this->config;
    $config['analytics']['track']['quota_usage'] = false;
    $this->quotaUsed = 100;

    ($this->handle)($config);

    expect($this->recorded)->toBe([]);
});

it('uses the route uri as the endpoint label', function (): void {
    $route = new Route(['PUT'], 'api/users/{user}', fn (): null => null);

    ($this->handle)($this->config, '/api/users/5', 'PUT', $route);

    expect($this->recorded[0]['endpoint'])->toBe('PUT /api/users/{user}');
});

it('uses the user as the analytics key', function (): void {
    $user = new stdClass;
    $user->id = 7;
    $user->plan = 'free';

    $request = Request::create('/api/users', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
    // Binding the request triggers the auth rebinding hook, which replaces the
    // user resolver, so the resolver is set afterwards.
    app()->instance('request', $request);
    $request->setUserResolver(fn (): stdClass => $user);

    $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->driver, $this->config));
    $middleware->handle($request, fn (): Response => new Response('ok'));

    expect($this->recorded[0]['key'])->toBe('user:7');
});

it('does not fail the request when recording fails', function (): void {
    $this->failRecording = true;

    $response = ($this->handle)($this->config);

    expect($response->getStatusCode())->toBe(200);
});

it('does not record bypassed requests', function (): void {
    $config = $this->config;
    $config['bypass'] = ['ips' => ['10.0.0.1']];

    ($this->handle)($config);

    expect($this->recorded)->toBe([]);
});

it('exposes whether an outcome is tracked', function (): void {
    $config = $this->config;
    $config['analytics']['track']['limited'] = false;
    $manager = new ThrottleSmartManager($this->driver, $config);

    expect($manager->shouldTrackAnalytics('allowed'))->toBeTrue()
        ->and($manager->shouldTrackAnalytics('rate_limited'))->toBeFalse()
        ->and($manager->shouldTrackAnalytics('quota_exceeded'))->toBeTrue();
});

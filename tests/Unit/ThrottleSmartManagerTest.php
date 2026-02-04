<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->config = [
        'default_plan' => 'free',
        'plans' => [
            'free' => [
                'requests_per_minute' => 60,
                'requests_per_hour' => 1000,
                'requests_per_month' => 10000,
            ],
            'pro' => [
                'requests_per_minute' => 300,
                'requests_per_hour' => 5000,
                'requests_per_month' => 100000,
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
        'bypass' => [
            'ips' => ['127.0.0.1'],
            'api_keys' => ['test-api-key'],
            'users' => [
                'ids' => [999],
                'attribute' => 'is_admin',
            ],
        ],
    ];
    $this->manager = new ThrottleSmartManager($this->driver, $this->config);
});

it('gets limits for anonymous user', function (): void {
    $this->driver->shouldReceive('increment')
        ->andReturn(['count' => 1, 'reset' => time() + 60]);

    $limits = $this->manager->getLimits(null);

    expect($limits)->toBeInstanceOf(RateLimits::class)
        ->and($limits->plan)->toBe('free');
});

it('gets limits for user with plan attribute', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'pro';

    $this->driver->shouldReceive('increment')
        ->andReturn(['count' => 1, 'reset' => time() + 60]);

    $limits = $this->manager->getLimits($user);

    expect($limits)->toBeInstanceOf(RateLimits::class)
        ->and($limits->plan)->toBe('pro');
});

it('detects when user is limited', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    $this->driver->shouldReceive('increment')
        ->andReturn(['count' => 61, 'reset' => time() + 60]);

    $limits = $this->manager->getLimits($user);

    expect($limits->isLimited)->toBeTrue()
        ->and($limits->limitedBy)->toBe('minute');
});

it('gets remaining for user', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    $this->driver->shouldReceive('get')
        ->andReturn(10);

    $remaining = $this->manager->getRemaining($user, 'minute');

    expect($remaining)->toBe(50);
});

it('returns max int for unlimited', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    // For a window without limit
    $remaining = $this->manager->getRemaining($user, 'second');

    expect($remaining)->toBe(PHP_INT_MAX);
});

it('gets quota for user', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    $this->driver->shouldReceive('getQuota')
        ->andReturn(5000);

    $quota = $this->manager->getQuota($user);

    expect($quota)->toBeInstanceOf(QuotaInfo::class);
});

it('consumes quota', function (): void {
    $user = new stdClass;
    $user->id = 123;

    $this->driver->shouldReceive('incrementQuota')
        ->twice();

    $this->manager->forUser($user)->consume(5);

    expect(true)->toBeTrue();
});

it('resets rate limits', function (): void {
    $this->driver->shouldReceive('reset')
        ->times(4);

    $this->manager->reset('user:123');

    expect(true)->toBeTrue();
});

it('resets quota for user', function (): void {
    $user = new stdClass;
    $user->id = 123;

    $this->driver->shouldReceive('resetQuota')
        ->twice();

    $this->manager->resetQuota($user);

    expect(true)->toBeTrue();
});

it('adds quota to user', function (): void {
    $user = new stdClass;
    $user->id = 123;

    $this->driver->shouldReceive('getQuota')
        ->andReturn(5000);

    $this->driver->shouldReceive('setQuota')
        ->once();

    $this->manager->addQuota($user, 1000, 'bonus');

    expect(true)->toBeTrue();
});

it('checks if request would be limited', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->andReturn($user);

    $this->driver->shouldReceive('get')
        ->andReturn(59);

    $result = $this->manager->wouldLimit($request);

    expect($result)->toBeFalse();
});

it('checks if request would be limited when over limit', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'free';

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->andReturn($user);

    $this->driver->shouldReceive('get')
        ->andReturn(60);

    $result = $this->manager->wouldLimit($request);

    expect($result)->toBeTrue();
});

it('bypasses request by IP', function (): void {
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn('127.0.0.1');
    $request->shouldReceive('user')->andReturn(null);
    $request->shouldReceive('header')->andReturn(null);

    $result = $this->manager->isBypassed($request);

    expect($result)->toBeTrue();
});

it('bypasses request by API key', function (): void {
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn('192.168.1.1');
    $request->shouldReceive('header')->with('X-API-Key')->andReturn('test-api-key');
    $request->shouldReceive('user')->andReturn(null);

    $result = $this->manager->isBypassed($request);

    expect($result)->toBeTrue();
});

it('bypasses request by user ID', function (): void {
    $user = new stdClass;
    $user->id = 999;

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn('192.168.1.1');
    $request->shouldReceive('header')->andReturn(null);
    $request->shouldReceive('user')->andReturn($user);

    $result = $this->manager->isBypassed($request);

    expect($result)->toBeTrue();
});

it('bypasses request by user attribute', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->is_admin = true;

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn('192.168.1.1');
    $request->shouldReceive('header')->andReturn(null);
    $request->shouldReceive('user')->andReturn($user);

    $result = $this->manager->isBypassed($request);

    expect($result)->toBeTrue();
});

it('does not bypass normal request', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->is_admin = false;

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('ip')->andReturn('192.168.1.1');
    $request->shouldReceive('header')->andReturn(null);
    $request->shouldReceive('user')->andReturn($user);

    $result = $this->manager->isBypassed($request);

    expect($result)->toBeFalse();
});

it('uses custom plan resolver', function (): void {
    $this->driver->shouldReceive('increment')
        ->andReturn(['count' => 1, 'reset' => time() + 60]);

    $this->manager->resolvePlanUsing(fn () => 'pro');

    $limits = $this->manager->getLimits(null);

    expect($limits->plan)->toBe('pro');
});

it('sets user context with forUser', function (): void {
    $user = new stdClass;
    $user->id = 123;
    $user->plan = 'pro';

    $this->driver->shouldReceive('increment')
        ->andReturn(['count' => 1, 'reset' => time() + 60]);

    $result = $this->manager->forUser($user);

    expect($result)->toBe($this->manager);

    $limits = $this->manager->getLimits();
    expect($limits->plan)->toBe('pro');
});

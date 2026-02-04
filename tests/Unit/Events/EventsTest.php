<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Events\QuotaExceeded;
use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Events\RateLimitExceeded;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;

it('creates RateLimitExceeded event with all properties', function (): void {
    $user = Mockery::mock(User::class);
    $request = Mockery::mock(Request::class);

    $event = new RateLimitExceeded(
        key: 'user:123',
        plan: 'free',
        limitType: 'minute',
        limit: 60,
        attempted: 61,
        user: $user,
        request: $request,
        retryAfter: 30,
    );

    expect($event->key)->toBe('user:123')
        ->and($event->plan)->toBe('free')
        ->and($event->limitType)->toBe('minute')
        ->and($event->limit)->toBe(60)
        ->and($event->attempted)->toBe(61)
        ->and($event->user)->toBe($user)
        ->and($event->request)->toBe($request)
        ->and($event->retryAfter)->toBe(30);
});

it('creates RateLimitExceeded event with null user', function (): void {
    $request = Mockery::mock(Request::class);

    $event = new RateLimitExceeded(
        key: 'ip:127.0.0.1',
        plan: 'guest',
        limitType: 'hour',
        limit: 100,
        attempted: 101,
        user: null,
        request: $request,
        retryAfter: 3600,
    );

    expect($event->user)->toBeNull()
        ->and($event->key)->toBe('ip:127.0.0.1');
});

it('creates RateLimitApproaching event with all properties', function (): void {
    $user = Mockery::mock(User::class);

    $event = new RateLimitApproaching(
        key: 'user:123',
        plan: 'pro',
        limitType: 'minute',
        limit: 300,
        used: 270,
        percentage: 90.0,
        user: $user,
    );

    expect($event->key)->toBe('user:123')
        ->and($event->plan)->toBe('pro')
        ->and($event->limitType)->toBe('minute')
        ->and($event->limit)->toBe(300)
        ->and($event->used)->toBe(270)
        ->and($event->percentage)->toBe(90.0)
        ->and($event->user)->toBe($user);
});

it('creates QuotaExceeded event with all properties', function (): void {
    $user = Mockery::mock(User::class);
    $resetsAt = now()->endOfMonth();

    $event = new QuotaExceeded(
        user: $user,
        quotaType: 'monthly',
        limit: 100000,
        used: 100001,
        resetsAt: $resetsAt,
    );

    expect($event->user)->toBe($user)
        ->and($event->quotaType)->toBe('monthly')
        ->and($event->limit)->toBe(100000)
        ->and($event->used)->toBe(100001)
        ->and($event->resetsAt)->toBe($resetsAt);
});

it('creates QuotaThresholdReached event with all properties', function (): void {
    $user = Mockery::mock(User::class);

    $event = new QuotaThresholdReached(
        user: $user,
        quotaType: 'monthly',
        limit: 100000,
        used: 80000,
        percentage: 80.0,
        threshold: 'warning',
    );

    expect($event->user)->toBe($user)
        ->and($event->quotaType)->toBe('monthly')
        ->and($event->limit)->toBe(100000)
        ->and($event->used)->toBe(80000)
        ->and($event->percentage)->toBe(80.0)
        ->and($event->threshold)->toBe('warning');
});

it('creates QuotaThresholdReached event with critical threshold', function (): void {
    $user = Mockery::mock(User::class);

    $event = new QuotaThresholdReached(
        user: $user,
        quotaType: 'monthly',
        limit: 100000,
        used: 95000,
        percentage: 95.0,
        threshold: 'critical',
    );

    expect($event->threshold)->toBe('critical')
        ->and($event->percentage)->toBe(95.0);
});

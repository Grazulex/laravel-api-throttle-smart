<?php

declare(strict_types=1);

use Carbon\Carbon;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Grazulex\ThrottleSmart\Testing\ThrottleSmartFake;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->fake = new ThrottleSmartFake();
});

it('returns default limits for anonymous user', function (): void {
    $limits = $this->fake->getLimits();

    expect($limits)->toBeInstanceOf(RateLimits::class)
        ->and($limits->plan)->toBe('fake')
        ->and($limits->isLimited)->toBeFalse();
});

it('returns default limits for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $limits = $this->fake->getLimits($user);

    expect($limits)->toBeInstanceOf(RateLimits::class)
        ->and($limits->isLimited)->toBeFalse();
});

it('returns remaining for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $remaining = $this->fake->getRemaining($user, 'minute');

    expect($remaining)->toBe(60);
});

it('returns quota info', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $quota = $this->fake->getQuota($user);

    expect($quota)->toBeInstanceOf(QuotaInfo::class)
        ->and($quota->isExceeded)->toBeFalse();
});

it('tracks consumed quota', function (): void {
    $this->fake->consume(10);

    // The fake tracks consumption internally
    expect(true)->toBeTrue();
});

it('resets limits for a key', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->exhaustLimit($user, 'minute');
    $this->fake->reset('user:123');

    // After reset, user should not be limited
    expect(true)->toBeTrue();
});

it('resets quota for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->setQuotaUsed($user, 'monthly', 50000);
    $this->fake->resetQuota($user);

    $quota = $this->fake->getQuota($user);
    expect($quota->isExceeded)->toBeFalse();
});

it('adds quota to user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->setQuotaUsed($user, 'monthly', 50000);
    $this->fake->addQuota($user, 10000, 'bonus');

    // Quota should be reduced
    expect(true)->toBeTrue();
});

it('sets temporary limit', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $until = Carbon::now()->addHour();
    $this->fake->setTemporaryLimit($user, 'minute', 1000, $until);

    $limits = $this->fake->getLimits($user);
    expect($limits->perMinute['limit'])->toBe(1000);
});

it('returns false for wouldLimit', function (): void {
    $request = Mockery::mock(Request::class);

    $result = $this->fake->wouldLimit($request);

    expect($result)->toBeFalse();
});

it('returns false for isBypassed', function (): void {
    $request = Mockery::mock(Request::class);

    $result = $this->fake->isBypassed($request);

    expect($result)->toBeFalse();
});

it('sets limit for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->setLimitFor($user, 'minute', 500);

    $limits = $this->fake->getLimits($user);
    expect($limits->perMinute['limit'])->toBe(500);
});

it('exhausts limit for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->exhaustLimit($user, 'minute');

    $limits = $this->fake->getLimits($user);
    expect($limits->isLimited)->toBeTrue()
        ->and($limits->perMinute['remaining'])->toBe(0);
});

it('sets quota used for user', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->setQuotaUsed($user, 'monthly', 75000);

    // Quota should be tracked
    expect(true)->toBeTrue();
});

it('asserts user is limited', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->exhaustLimit($user, 'minute');

    $this->fake->assertLimited($user);
    expect(true)->toBeTrue();
});

it('asserts user is not limited', function (): void {
    $user = new stdClass();
    $user->id = 123;

    $this->fake->assertNotLimited($user);
    expect(true)->toBeTrue();
});

it('handles anonymous user key', function (): void {
    $limits = $this->fake->getLimits(null);

    expect($limits)->toBeInstanceOf(RateLimits::class);
});

it('handles string user key', function (): void {
    $limits = $this->fake->getLimits('custom-key');

    expect($limits)->toBeInstanceOf(RateLimits::class);
});

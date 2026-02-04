<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Exceptions\QuotaExceededException;
use Grazulex\ThrottleSmart\Exceptions\RateLimitExceededException;
use Grazulex\ThrottleSmart\Exceptions\ThrottleSmartException;

it('creates ThrottleSmartException with message', function (): void {
    $exception = new ThrottleSmartException('Test error message');

    expect($exception->getMessage())->toBe('Test error message')
        ->and($exception)->toBeInstanceOf(Exception::class);
});

it('creates RateLimitExceededException with all properties', function (): void {
    $exception = new RateLimitExceededException(
        limitType: 'minute',
        limit: 60,
        retryAfter: 30,
        plan: 'free',
    );

    expect($exception->limitType)->toBe('minute')
        ->and($exception->limit)->toBe(60)
        ->and($exception->retryAfter)->toBe(30)
        ->and($exception->plan)->toBe('free')
        ->and($exception->getMessage())->toContain('minute')
        ->and($exception->getMessage())->toContain('30 seconds');
});

it('creates RateLimitExceededException without plan', function (): void {
    $exception = new RateLimitExceededException(
        limitType: 'hour',
        limit: 1000,
        retryAfter: 3600,
    );

    expect($exception->plan)->toBeNull()
        ->and($exception->limitType)->toBe('hour')
        ->and($exception->limit)->toBe(1000)
        ->and($exception->retryAfter)->toBe(3600);
});

it('creates RateLimitExceededException with correct message format', function (): void {
    $exception = new RateLimitExceededException(
        limitType: 'day',
        limit: 10000,
        retryAfter: 86400,
    );

    expect($exception->getMessage())->toBe('Rate limit exceeded for day. Retry after 86400 seconds.');
});

it('RateLimitExceededException extends ThrottleSmartException', function (): void {
    $exception = new RateLimitExceededException(
        limitType: 'minute',
        limit: 60,
        retryAfter: 30,
    );

    expect($exception)->toBeInstanceOf(ThrottleSmartException::class);
});

it('creates QuotaExceededException with all properties', function (): void {
    $resetsAt = now()->endOfMonth();

    $exception = new QuotaExceededException(
        quotaType: 'monthly',
        limit: 100000,
        used: 100001,
        resetsAt: $resetsAt,
    );

    expect($exception->quotaType)->toBe('monthly')
        ->and($exception->limit)->toBe(100000)
        ->and($exception->used)->toBe(100001)
        ->and($exception->resetsAt)->toBe($resetsAt)
        ->and($exception->getMessage())->toContain('monthly');
});

it('creates QuotaExceededException without resetsAt', function (): void {
    $exception = new QuotaExceededException(
        quotaType: 'daily',
        limit: 5000,
        used: 5001,
    );

    expect($exception->resetsAt)->toBeNull()
        ->and($exception->quotaType)->toBe('daily');
});

it('QuotaExceededException extends ThrottleSmartException', function (): void {
    $exception = new QuotaExceededException(
        quotaType: 'monthly',
        limit: 100000,
        used: 100001,
        resetsAt: now(),
    );

    expect($exception)->toBeInstanceOf(ThrottleSmartException::class);
});

it('QuotaExceededException has correct message format', function (): void {
    $resetsAt = now()->endOfMonth();

    $exception = new QuotaExceededException(
        quotaType: 'monthly',
        limit: 100000,
        used: 100001,
        resetsAt: $resetsAt,
    );

    expect($exception->getMessage())->toContain('Quota exceeded')
        ->and($exception->getMessage())->toContain('monthly')
        ->and($exception->getMessage())->toContain('100001')
        ->and($exception->getMessage())->toContain('100000');
});

it('QuotaExceededException includes reset time in message', function (): void {
    $resetsAt = now()->endOfMonth();

    $exception = new QuotaExceededException(
        quotaType: 'monthly',
        limit: 100000,
        used: 100001,
        resetsAt: $resetsAt,
    );

    expect($exception->getMessage())->toContain('Resets at');
});

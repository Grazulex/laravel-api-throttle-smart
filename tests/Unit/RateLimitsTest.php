<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Support\RateLimits;

test('rate limits can be created from array', function () {
    $limits = RateLimits::fromArray('pro', [
        'minute' => ['limit' => 300, 'remaining' => 250, 'reset' => time() + 60],
        'hour' => ['limit' => 5000, 'remaining' => 4500, 'reset' => time() + 3600],
    ]);

    expect($limits->plan)->toBe('pro')
        ->and($limits->perMinute['limit'])->toBe(300)
        ->and($limits->perMinute['remaining'])->toBe(250)
        ->and($limits->isLimited)->toBeFalse();
});

test('rate limits indicates when limited', function () {
    $limits = RateLimits::fromArray('free', [
        'minute' => ['limit' => 60, 'remaining' => 0, 'reset' => time() + 45],
    ], true, 'minute');

    expect($limits->isLimited)->toBeTrue()
        ->and($limits->limitedBy)->toBe('minute')
        ->and($limits->retryAfter)->toBeGreaterThan(0);
});

test('get primary limit returns minute by default', function () {
    $limits = RateLimits::fromArray('pro', [
        'minute' => ['limit' => 300, 'remaining' => 200, 'reset' => time() + 60],
        'hour' => ['limit' => 5000, 'remaining' => 4000, 'reset' => time() + 3600],
    ]);

    $primary = $limits->getPrimaryLimit();

    expect($primary['limit'])->toBe(300);
});

test('rate limits can be converted to array', function () {
    $limits = RateLimits::fromArray('test', [
        'minute' => ['limit' => 60, 'remaining' => 50, 'reset' => time()],
    ]);

    $array = $limits->toArray();

    expect($array)->toBeArray()
        ->and($array['plan'])->toBe('test')
        ->and($array['is_limited'])->toBeFalse();
});

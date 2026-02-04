<?php

declare(strict_types=1);

use Carbon\Carbon;
use Grazulex\ThrottleSmart\Support\QuotaInfo;

test('quota info can be created from usage', function () {
    $quota = QuotaInfo::fromUsage(
        monthlyLimit: 100000,
        monthlyUsed: 45000,
        dailyLimit: 5000,
        dailyUsed: 1000,
        resetsAt: Carbon::now()->endOfMonth()
    );

    expect($quota->monthly['limit'])->toBe(100000)
        ->and($quota->monthly['used'])->toBe(45000)
        ->and($quota->monthly['remaining'])->toBe(55000)
        ->and($quota->isExceeded)->toBeFalse()
        ->and($quota->percentageUsed)->toBe(45.0);
});

test('quota info indicates when exceeded', function () {
    $quota = QuotaInfo::fromUsage(
        monthlyLimit: 100000,
        monthlyUsed: 100000,
        dailyLimit: null,
        dailyUsed: 0
    );

    expect($quota->isExceeded)->toBeTrue()
        ->and($quota->percentageUsed)->toBe(100.0);
});

test('quota info returns remaining correctly', function () {
    $quota = QuotaInfo::fromUsage(
        monthlyLimit: 100000,
        monthlyUsed: 80000,
        dailyLimit: 5000,
        dailyUsed: 4500
    );

    // Should return the more restrictive (daily: 500 remaining)
    expect($quota->getRemaining())->toBe(500);
});

test('quota info handles unlimited monthly', function () {
    $quota = QuotaInfo::fromUsage(
        monthlyLimit: null,
        monthlyUsed: 0,
        dailyLimit: 5000,
        dailyUsed: 1000
    );

    expect($quota->monthly)->toBeNull()
        ->and($quota->daily['remaining'])->toBe(4000);
});

<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Support\Plan;

test('plan can be created from config', function () {
    $config = [
        'label' => 'Test Plan',
        'requests_per_minute' => 100,
        'requests_per_hour' => 1000,
        'requests_per_month' => 50000,
        'burst_size' => 20,
    ];

    $plan = Plan::fromConfig('test', $config);

    expect($plan->name)->toBe('test')
        ->and($plan->label)->toBe('Test Plan')
        ->and($plan->requestsPerMinute)->toBe(100)
        ->and($plan->requestsPerHour)->toBe(1000)
        ->and($plan->requestsPerMonth)->toBe(50000)
        ->and($plan->burstSize)->toBe(20);
});

test('plan returns null for unlimited limits', function () {
    $config = [
        'requests_per_minute' => null,
    ];

    $plan = Plan::fromConfig('unlimited', $config);

    expect($plan->getLimit('minute'))->toBeNull()
        ->and($plan->isUnlimited('minute'))->toBeTrue();
});

test('plan can be converted to array', function () {
    $plan = Plan::fromConfig('test', [
        'requests_per_minute' => 60,
    ]);

    $array = $plan->toArray();

    expect($array)->toBeArray()
        ->and($array['name'])->toBe('test')
        ->and($array['requests_per_minute'])->toBe(60);
});

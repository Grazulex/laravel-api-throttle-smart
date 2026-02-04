<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Attributes\QuotaCost;
use Grazulex\ThrottleSmart\Attributes\RateLimit;

it('creates RateLimit attribute with default values', function (): void {
    $attribute = new RateLimit;

    expect($attribute->perSecond)->toBeNull()
        ->and($attribute->perMinute)->toBeNull()
        ->and($attribute->perHour)->toBeNull()
        ->and($attribute->perDay)->toBeNull()
        ->and($attribute->scope)->toBeNull()
        ->and($attribute->bypass)->toBeFalse();
});

it('creates RateLimit attribute with per minute limit', function (): void {
    $attribute = new RateLimit(perMinute: 100);

    expect($attribute->perMinute)->toBe(100)
        ->and($attribute->perSecond)->toBeNull()
        ->and($attribute->perHour)->toBeNull();
});

it('creates RateLimit attribute with all limits', function (): void {
    $attribute = new RateLimit(
        perSecond: 10,
        perMinute: 100,
        perHour: 1000,
        perDay: 10000,
        scope: 'user',
        bypass: false,
    );

    expect($attribute->perSecond)->toBe(10)
        ->and($attribute->perMinute)->toBe(100)
        ->and($attribute->perHour)->toBe(1000)
        ->and($attribute->perDay)->toBe(10000)
        ->and($attribute->scope)->toBe('user')
        ->and($attribute->bypass)->toBeFalse();
});

it('creates RateLimit attribute with bypass enabled', function (): void {
    $attribute = new RateLimit(bypass: true);

    expect($attribute->bypass)->toBeTrue();
});

it('creates RateLimit attribute with custom scope', function (): void {
    $attribute = new RateLimit(perMinute: 50, scope: 'ip');

    expect($attribute->scope)->toBe('ip')
        ->and($attribute->perMinute)->toBe(50);
});

it('creates QuotaCost attribute with default value', function (): void {
    $attribute = new QuotaCost;

    expect($attribute->cost)->toBe(1);
});

it('creates QuotaCost attribute with custom cost', function (): void {
    $attribute = new QuotaCost(cost: 10);

    expect($attribute->cost)->toBe(10);
});

it('creates QuotaCost attribute with zero cost', function (): void {
    $attribute = new QuotaCost(cost: 0);

    expect($attribute->cost)->toBe(0);
});

it('creates QuotaCost attribute with high cost', function (): void {
    $attribute = new QuotaCost(cost: 100);

    expect($attribute->cost)->toBe(100);
});

it('RateLimit attribute has correct target', function (): void {
    $reflection = new ReflectionClass(RateLimit::class);
    $attributes = $reflection->getAttributes();

    expect($attributes)->not->toBeEmpty();

    $attribute = $attributes[0]->newInstance();
    expect($attribute)->toBeInstanceOf(Attribute::class);
});

it('QuotaCost attribute has correct target', function (): void {
    $reflection = new ReflectionClass(QuotaCost::class);
    $attributes = $reflection->getAttributes();

    expect($attributes)->not->toBeEmpty();

    $attribute = $attributes[0]->newInstance();
    expect($attribute)->toBeInstanceOf(Attribute::class);
});

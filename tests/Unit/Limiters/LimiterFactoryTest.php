<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Limiters\FixedWindowLimiter;
use Grazulex\ThrottleSmart\Limiters\LimiterFactory;
use Grazulex\ThrottleSmart\Limiters\SlidingWindowLimiter;
use Grazulex\ThrottleSmart\Limiters\TokenBucketLimiter;

beforeEach(function (): void {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
});

it('creates fixed window limiter by default', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    $limiter = $factory->make();

    expect($limiter)->toBeInstanceOf(FixedWindowLimiter::class);
});

it('creates fixed window limiter explicitly', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    $limiter = $factory->make('fixed_window');

    expect($limiter)->toBeInstanceOf(FixedWindowLimiter::class);
});

it('creates sliding window limiter when enabled', function (): void {
    $factory = new LimiterFactory($this->driver, [
        'sliding_window' => ['enabled' => true, 'precision' => 1],
    ]);

    $limiter = $factory->make();

    expect($limiter)->toBeInstanceOf(SlidingWindowLimiter::class);
});

it('creates sliding window limiter explicitly', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    $limiter = $factory->make('sliding_window');

    expect($limiter)->toBeInstanceOf(SlidingWindowLimiter::class);
});

it('creates token bucket limiter when enabled', function (): void {
    $factory = new LimiterFactory($this->driver, [
        'token_bucket' => ['enabled' => true, 'burst_size' => 10],
    ]);

    $limiter = $factory->make();

    expect($limiter)->toBeInstanceOf(TokenBucketLimiter::class);
});

it('creates token bucket limiter explicitly', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    $limiter = $factory->make('token_bucket');

    expect($limiter)->toBeInstanceOf(TokenBucketLimiter::class);
});

it('prioritizes sliding window over token bucket', function (): void {
    $factory = new LimiterFactory($this->driver, [
        'sliding_window' => ['enabled' => true],
        'token_bucket' => ['enabled' => true],
    ]);

    $limiter = $factory->make();

    expect($limiter)->toBeInstanceOf(SlidingWindowLimiter::class);
});

it('supports short algorithm names', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    expect($factory->make('fixed'))->toBeInstanceOf(FixedWindowLimiter::class)
        ->and($factory->make('sliding'))->toBeInstanceOf(SlidingWindowLimiter::class)
        ->and($factory->make('bucket'))->toBeInstanceOf(TokenBucketLimiter::class);
});

it('throws exception for unknown algorithm', function (): void {
    $factory = new LimiterFactory($this->driver, []);

    $factory->make('unknown');
})->throws(InvalidArgumentException::class, 'Unknown limiter algorithm: unknown');

it('provides available algorithms list', function (): void {
    $algorithms = LimiterFactory::availableAlgorithms();

    expect($algorithms)->toHaveKey('fixed_window')
        ->and($algorithms)->toHaveKey('sliding_window')
        ->and($algorithms)->toHaveKey('token_bucket');
});

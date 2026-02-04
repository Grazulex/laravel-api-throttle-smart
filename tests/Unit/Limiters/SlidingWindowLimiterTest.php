<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Limiters\SlidingWindowLimiter;

beforeEach(function (): void {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->limiter = new SlidingWindowLimiter($this->driver, ['precision' => 1]);
});

it('allows request when weighted count is under limit', function (): void {
    $now = time();
    $windowSeconds = 60;
    $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
    $previousWindowStart = $windowStart - $windowSeconds;

    $currentKey = "test-key:sliding:{$windowStart}";
    $previousKey = "test-key:sliding:{$previousWindowStart}";

    $this->driver->shouldReceive('increment')
        ->with($currentKey, 120)
        ->once()
        ->andReturn(['count' => 3, 'reset' => $windowStart + $windowSeconds]);

    $this->driver->shouldReceive('get')
        ->with($previousKey)
        ->once()
        ->andReturn(5);

    $result = $this->limiter->attempt('test-key', 10, 60);

    expect($result['allowed'])->toBeTrue()
        ->and($result['limit'])->toBe(10);
});

it('blocks request when weighted count exceeds limit', function (): void {
    $now = time();
    $windowSeconds = 60;
    $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
    $previousWindowStart = $windowStart - $windowSeconds;

    $currentKey = "test-key:sliding:{$windowStart}";
    $previousKey = "test-key:sliding:{$previousWindowStart}";

    // Use 11 current requests which alone exceeds the limit of 10
    // This ensures the test passes regardless of overlap percentage
    $this->driver->shouldReceive('increment')
        ->with($currentKey, 120)
        ->once()
        ->andReturn(['count' => 11, 'reset' => $windowStart + $windowSeconds]);

    $this->driver->shouldReceive('get')
        ->with($previousKey)
        ->once()
        ->andReturn(5);

    $result = $this->limiter->attempt('test-key', 10, 60);

    // With 11 current requests, should exceed limit of 10
    expect($result['allowed'])->toBeFalse();
});

it('handles no previous window data', function (): void {
    $now = time();
    $windowSeconds = 60;
    $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
    $previousWindowStart = $windowStart - $windowSeconds;

    $currentKey = "test-key:sliding:{$windowStart}";
    $previousKey = "test-key:sliding:{$previousWindowStart}";

    $this->driver->shouldReceive('increment')
        ->with($currentKey, 120)
        ->once()
        ->andReturn(['count' => 5, 'reset' => $windowStart + $windowSeconds]);

    $this->driver->shouldReceive('get')
        ->with($previousKey)
        ->once()
        ->andReturn(null);

    $result = $this->limiter->attempt('test-key', 10, 60);

    expect($result['allowed'])->toBeTrue()
        ->and($result['remaining'])->toBe(5);
});

it('resets both windows', function (): void {
    $now = time();
    $windowSeconds = 60;
    $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
    $previousWindowStart = $windowStart - $windowSeconds;

    $currentKey = "test-key:sliding:{$windowStart}";
    $previousKey = "test-key:sliding:{$previousWindowStart}";

    $this->driver->shouldReceive('reset')
        ->with($currentKey)
        ->once()
        ->andReturn(true);

    $this->driver->shouldReceive('reset')
        ->with($previousKey)
        ->once()
        ->andReturn(true);

    $result = $this->limiter->reset('test-key');

    expect($result)->toBeTrue();
});

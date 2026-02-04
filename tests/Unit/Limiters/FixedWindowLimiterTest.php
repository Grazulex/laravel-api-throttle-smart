<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Limiters\FixedWindowLimiter;

beforeEach(function (): void {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->limiter = new FixedWindowLimiter($this->driver);
});

it('allows request when under limit', function (): void {
    $this->driver->shouldReceive('increment')
        ->with('test-key', 60)
        ->once()
        ->andReturn(['count' => 5, 'reset' => time() + 60]);

    $result = $this->limiter->attempt('test-key', 10, 60);

    expect($result['allowed'])->toBeTrue()
        ->and($result['remaining'])->toBe(5)
        ->and($result['limit'])->toBe(10);
});

it('blocks request when at limit', function (): void {
    $this->driver->shouldReceive('increment')
        ->with('test-key', 60)
        ->once()
        ->andReturn(['count' => 11, 'reset' => time() + 60]);

    $result = $this->limiter->attempt('test-key', 10, 60);

    expect($result['allowed'])->toBeFalse()
        ->and($result['remaining'])->toBe(0);
});

it('allows exactly at limit', function (): void {
    $this->driver->shouldReceive('increment')
        ->with('test-key', 60)
        ->once()
        ->andReturn(['count' => 10, 'reset' => time() + 60]);

    $result = $this->limiter->attempt('test-key', 10, 60);

    expect($result['allowed'])->toBeTrue()
        ->and($result['remaining'])->toBe(0);
});

it('returns remaining count', function (): void {
    $this->driver->shouldReceive('get')
        ->with('test-key')
        ->once()
        ->andReturn(7);

    $remaining = $this->limiter->remaining('test-key', 10);

    expect($remaining)->toBe(3);
});

it('returns zero remaining when over limit', function (): void {
    $this->driver->shouldReceive('get')
        ->with('test-key')
        ->once()
        ->andReturn(15);

    $remaining = $this->limiter->remaining('test-key', 10);

    expect($remaining)->toBe(0);
});

it('returns full remaining when no requests made', function (): void {
    $this->driver->shouldReceive('get')
        ->with('test-key')
        ->once()
        ->andReturn(null);

    $remaining = $this->limiter->remaining('test-key', 10);

    expect($remaining)->toBe(10);
});

it('calculates reset time from ttl', function (): void {
    $this->driver->shouldReceive('ttl')
        ->with('test-key')
        ->once()
        ->andReturn(30);

    $resetAt = $this->limiter->resetAt('test-key');

    expect($resetAt)->toBeGreaterThanOrEqual(time() + 29)
        ->and($resetAt)->toBeLessThanOrEqual(time() + 31);
});

it('resets the key', function (): void {
    $this->driver->shouldReceive('reset')
        ->with('test-key')
        ->once()
        ->andReturn(true);

    $result = $this->limiter->reset('test-key');

    expect($result)->toBeTrue();
});

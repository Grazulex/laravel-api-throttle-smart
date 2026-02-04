<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Limiters\TokenBucketLimiter;

beforeEach(function (): void {
    $this->driver = Mockery::mock(StorageDriverInterface::class);
    $this->limiter = new TokenBucketLimiter($this->driver, [
        'burst_size' => 10,
        'refill_rate' => 1.0,
    ]);
});

it('allows request when tokens available', function (): void {
    $this->driver->shouldReceive('acquireToken')
        ->with('test-key', 10, 1.0)
        ->once()
        ->andReturn([
            'allowed' => true,
            'tokens' => 9,
            'reset' => time() + 10,
        ]);

    $result = $this->limiter->attempt('test-key', 100, 60);

    expect($result['allowed'])->toBeTrue()
        ->and($result['remaining'])->toBe(9)
        ->and($result['limit'])->toBe(10);
});

it('blocks request when no tokens available', function (): void {
    $this->driver->shouldReceive('acquireToken')
        ->with('test-key', 10, 1.0)
        ->once()
        ->andReturn([
            'allowed' => false,
            'tokens' => 0,
            'reset' => time() + 1,
        ]);

    $result = $this->limiter->attempt('test-key', 100, 60);

    expect($result['allowed'])->toBeFalse()
        ->and($result['remaining'])->toBe(0);
});

it('uses custom burst size', function (): void {
    $limiter = new TokenBucketLimiter($this->driver, [
        'burst_size' => 50,
        'refill_rate' => 5.0,
    ]);

    $this->driver->shouldReceive('acquireToken')
        ->with('test-key', 50, 5.0)
        ->once()
        ->andReturn([
            'allowed' => true,
            'tokens' => 49,
            'reset' => time() + 10,
        ]);

    $result = $limiter->attempt('test-key', 100, 60);

    expect($result['limit'])->toBe(50);
});

it('can be configured with plan settings', function (): void {
    $this->limiter->configure([
        'burst_size' => 20,
        'burst_refill_rate' => 2.0,
    ]);

    $this->driver->shouldReceive('acquireToken')
        ->with('test-key', 20, 2.0)
        ->once()
        ->andReturn([
            'allowed' => true,
            'tokens' => 19,
            'reset' => time() + 10,
        ]);

    $result = $this->limiter->attempt('test-key', 100, 60);

    expect($result['limit'])->toBe(20);
});

it('resets the bucket key', function (): void {
    $this->driver->shouldReceive('reset')
        ->with('bucket:test-key')
        ->once()
        ->andReturn(true);

    $result = $this->limiter->reset('test-key');

    expect($result)->toBeTrue();
});

it('calculates refill rate from limit and window when not configured', function (): void {
    $limiter = new TokenBucketLimiter($this->driver, [
        'burst_size' => 0,
        'refill_rate' => 0,
    ]);

    // When burst_size is 0, it should use the limit
    // When refill_rate is 0, it should calculate from limit/windowSeconds
    $this->driver->shouldReceive('acquireToken')
        ->withArgs(function ($key, $maxTokens, $refillRate) {
            // limit (100) should be used, refill rate should be 100/60
            return $key === 'test-key'
                && $maxTokens === 100
                && abs($refillRate - (100 / 60)) < 0.01;
        })
        ->once()
        ->andReturn([
            'allowed' => true,
            'tokens' => 99,
            'reset' => time() + 60,
        ]);

    $result = $limiter->attempt('test-key', 100, 60);

    expect($result['allowed'])->toBeTrue();
});

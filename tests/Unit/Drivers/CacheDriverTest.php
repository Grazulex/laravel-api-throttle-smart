<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Drivers\CacheDriver;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheManager::class);
    $this->repository = Mockery::mock(Repository::class);
    $this->cache->shouldReceive('store')->andReturn($this->repository);
});

it('increments counter for first request', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('increment')
        ->with('test:test-key')
        ->once()
        ->andReturn(1);

    $this->repository->shouldReceive('put')
        ->with('test:test-key', 1, 60)
        ->once();

    $result = $driver->increment('test-key', 60);

    expect($result['count'])->toBe(1)
        ->and($result['reset'])->toBeGreaterThan(time());
});

it('increments counter for subsequent requests', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('increment')
        ->with('test:test-key')
        ->once()
        ->andReturn(5);

    $result = $driver->increment('test-key', 60);

    expect($result['count'])->toBe(5);
});

it('gets value from cache', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('get')
        ->with('test:test-key')
        ->once()
        ->andReturn(10);

    $result = $driver->get('test-key');

    expect($result)->toBe(10);
});

it('returns null when key does not exist', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('get')
        ->with('test:test-key')
        ->once()
        ->andReturn(null);

    $result = $driver->get('test-key');

    expect($result)->toBeNull();
});

it('resets a key', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('forget')
        ->with('test:test-key')
        ->once()
        ->andReturn(true);

    $result = $driver->reset('test-key');

    expect($result)->toBeTrue();
});

it('gets quota', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('get')
        ->with('test:quota:user-123', 0)
        ->once()
        ->andReturn(500);

    $result = $driver->getQuota('user-123');

    expect($result)->toBe(500);
});

it('resets quota', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('forget')
        ->with('test:quota:user-123')
        ->once()
        ->andReturn(true);

    $result = $driver->resetQuota('user-123');

    expect($result)->toBeTrue();
});

it('returns default ttl', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $result = $driver->ttl('test-key');

    expect($result)->toBe(60);
});

it('returns empty analytics', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $result = $driver->getAnalytics();

    expect($result)->toBe([]);
});

it('returns zero on cleanup', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $result = $driver->cleanup();

    expect($result)->toBe(0);
});

it('acquires token when tokens available', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('get')
        ->with('test:bucket_time:test-key', Mockery::any())
        ->once()
        ->andReturn(microtime(true) - 10);

    $this->repository->shouldReceive('get')
        ->with('test:bucket:test-key', 10)
        ->once()
        ->andReturn(10);

    $this->repository->shouldReceive('put')
        ->twice();

    $result = $driver->acquireToken('test-key', 10, 1.0);

    expect($result['allowed'])->toBeTrue()
        ->and($result['tokens'])->toBeGreaterThanOrEqual(0);
});

it('denies token when bucket empty', function (): void {
    $driver = new CacheDriver($this->cache, ['prefix' => 'test:', 'store' => 'default']);

    $this->repository->shouldReceive('get')
        ->with('test:bucket_time:test-key', Mockery::any())
        ->once()
        ->andReturn(microtime(true));

    $this->repository->shouldReceive('get')
        ->with('test:bucket:test-key', 10)
        ->once()
        ->andReturn(0.5);

    $result = $driver->acquireToken('test-key', 10, 1.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['tokens'])->toBe(0);
});

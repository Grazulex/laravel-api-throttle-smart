<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Limiters;

use Grazulex\ThrottleSmart\Contracts\RateLimiterInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use InvalidArgumentException;

/**
 * Factory for creating rate limiter instances.
 *
 * Resolves the appropriate limiter based on configuration.
 */
class LimiterFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected StorageDriverInterface $driver,
        protected array $config = [],
    ) {}

    /**
     * Create the appropriate limiter based on configuration.
     */
    public function make(?string $algorithm = null): RateLimiterInterface
    {
        $algorithm = $algorithm ?? $this->resolveAlgorithm();

        return match ($algorithm) {
            'fixed_window', 'fixed' => $this->makeFixedWindow(),
            'sliding_window', 'sliding' => $this->makeSlidingWindow(),
            'token_bucket', 'bucket' => $this->makeTokenBucket(),
            default => throw new InvalidArgumentException("Unknown limiter algorithm: {$algorithm}"),
        };
    }

    /**
     * Create a fixed window limiter.
     */
    public function makeFixedWindow(): FixedWindowLimiter
    {
        return new FixedWindowLimiter($this->driver);
    }

    /**
     * Create a sliding window limiter.
     */
    public function makeSlidingWindow(): SlidingWindowLimiter
    {
        $slidingConfig = $this->config['sliding_window'] ?? [];

        return new SlidingWindowLimiter($this->driver, $slidingConfig);
    }

    /**
     * Create a token bucket limiter.
     */
    public function makeTokenBucket(): TokenBucketLimiter
    {
        $bucketConfig = $this->config['token_bucket'] ?? [];

        return new TokenBucketLimiter($this->driver, $bucketConfig);
    }

    /**
     * Get all available algorithms.
     *
     * @return array<string, string>
     */
    public static function availableAlgorithms(): array
    {
        return [
            'fixed_window' => 'Fixed Window - Simple counter with discrete time windows',
            'sliding_window' => 'Sliding Window - Weighted average across windows for smoother limiting',
            'token_bucket' => 'Token Bucket - Allows bursts while maintaining average rate',
        ];
    }

    /**
     * Resolve which algorithm to use based on configuration.
     */
    protected function resolveAlgorithm(): string
    {
        // Check if sliding window is enabled
        $slidingConfig = $this->config['sliding_window'] ?? [];
        if ($slidingConfig['enabled'] ?? false) {
            return 'sliding_window';
        }

        // Check if token bucket is enabled (for burst handling)
        $bucketConfig = $this->config['token_bucket'] ?? [];
        if ($bucketConfig['enabled'] ?? false) {
            return 'token_bucket';
        }

        // Default to fixed window
        return 'fixed_window';
    }
}

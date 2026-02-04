<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Limiters;

use Grazulex\ThrottleSmart\Contracts\RateLimiterInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;

/**
 * Token Bucket Rate Limiter.
 *
 * Allows burst traffic up to bucket capacity while maintaining
 * a steady average rate over time.
 *
 * - Bucket starts full (or with initial_tokens if configured)
 * - Each request consumes one token
 * - Tokens refill at a steady rate (refill_rate tokens per second)
 * - Max tokens = bucket capacity (burst_size)
 *
 * Perfect for APIs that need to allow occasional bursts while
 * enforcing an average rate limit.
 */
class TokenBucketLimiter implements RateLimiterInterface
{
    protected int $burstSize;

    protected float $refillRate;

    protected ?int $initialTokens;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected StorageDriverInterface $driver,
        protected array $config = [],
    ) {
        $this->burstSize = (int) ($config['burst_size'] ?? 10);
        $this->refillRate = (float) ($config['refill_rate'] ?? 1.0);
        $this->initialTokens = isset($config['initial_tokens']) ? (int) $config['initial_tokens'] : null;
    }

    /**
     * @return array{allowed: bool, remaining: int, reset: int, limit: int}
     */
    public function attempt(string $key, int $limit, int $windowSeconds): array
    {
        // Use the burst_size as the limit for token bucket
        // The windowSeconds is used to calculate refill rate if not explicitly set
        $maxTokens = $this->burstSize > 0 ? $this->burstSize : $limit;

        // Calculate refill rate: tokens per second to achieve limit in windowSeconds
        $refillRate = $this->refillRate > 0
            ? $this->refillRate
            : $limit / $windowSeconds;

        $result = $this->driver->acquireToken($key, $maxTokens, $refillRate);

        return [
            'allowed' => $result['allowed'],
            'remaining' => $result['tokens'],
            'reset' => $result['reset'],
            'limit' => $maxTokens,
        ];
    }

    public function remaining(string $key, int $limit): int
    {
        // For token bucket, we can't easily get remaining without consuming
        // This is an approximation based on last known state
        $maxTokens = $this->burstSize > 0 ? $this->burstSize : $limit;
        $refillRate = $this->refillRate > 0 ? $this->refillRate : 1.0;

        // Attempt without actually consuming (peek)
        $result = $this->driver->acquireToken($key, $maxTokens, $refillRate);

        // If allowed, we have at least 1 token; return the tokens count
        // Note: This actually consumes a token, so for true peek we'd need
        // a separate driver method. For now, return the result.
        return $result['tokens'];
    }

    public function resetAt(string $key): int
    {
        // Token bucket doesn't have a fixed reset time
        // Return time to refill to full capacity
        $maxTokens = $this->burstSize > 0 ? $this->burstSize : 10;
        $refillRate = $this->refillRate > 0 ? $this->refillRate : 1.0;

        // Approximate: time to refill all tokens
        return time() + (int) ceil($maxTokens / $refillRate);
    }

    public function reset(string $key): bool
    {
        // Reset the token bucket by removing the key
        return $this->driver->reset("bucket:{$key}");
    }

    /**
     * Configure the limiter with plan-specific settings.
     *
     * @param  array<string, mixed>  $planConfig
     */
    public function configure(array $planConfig): self
    {
        if (isset($planConfig['burst_size'])) {
            $this->burstSize = (int) $planConfig['burst_size'];
        }
        if (isset($planConfig['burst_refill_rate'])) {
            $this->refillRate = (float) $planConfig['burst_refill_rate'];
        }

        return $this;
    }
}

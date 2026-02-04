<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Limiters;

use Grazulex\ThrottleSmart\Contracts\RateLimiterInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;

/**
 * Fixed Window Rate Limiter.
 *
 * Simple counter-based rate limiting with fixed time windows.
 * Requests are counted within discrete time windows (e.g., 0:00-0:59, 1:00-1:59).
 *
 * Pros: Simple, efficient, low storage overhead
 * Cons: Can allow burst at window boundaries (2x limit in worst case)
 */
class FixedWindowLimiter implements RateLimiterInterface
{
    public function __construct(
        protected StorageDriverInterface $driver,
    ) {}

    /**
     * @return array{allowed: bool, remaining: int, reset: int, limit: int}
     */
    public function attempt(string $key, int $limit, int $windowSeconds): array
    {
        $result = $this->driver->increment($key, $windowSeconds);
        $count = $result['count'];
        $reset = $result['reset'];

        $allowed = $count <= $limit;
        $remaining = max(0, $limit - $count);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset' => $reset,
            'limit' => $limit,
        ];
    }

    public function remaining(string $key, int $limit): int
    {
        $count = $this->driver->get($key) ?? 0;

        return max(0, $limit - $count);
    }

    public function resetAt(string $key): int
    {
        $ttl = $this->driver->ttl($key);

        return $ttl > 0 ? time() + $ttl : time();
    }

    public function reset(string $key): bool
    {
        return $this->driver->reset($key);
    }
}

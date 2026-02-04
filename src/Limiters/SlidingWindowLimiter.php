<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Limiters;

use Grazulex\ThrottleSmart\Contracts\RateLimiterInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;

/**
 * Sliding Window Rate Limiter.
 *
 * Combines counts from current and previous windows with weighted averaging
 * based on how far we are into the current window.
 *
 * This provides smoother rate limiting than fixed windows and prevents
 * the "burst at boundary" problem.
 *
 * Formula: weighted_count = (previous_count * overlap_percentage) + current_count
 * Where overlap_percentage = (window_size - elapsed_time) / window_size
 */
class SlidingWindowLimiter implements RateLimiterInterface
{
    protected int $precision;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected StorageDriverInterface $driver,
        protected array $config = [],
    ) {
        $this->precision = (int) ($config['precision'] ?? 1);
    }

    /**
     * @return array{allowed: bool, remaining: int, reset: int, limit: int}
     */
    public function attempt(string $key, int $limit, int $windowSeconds): array
    {
        $now = time();
        $currentWindow = $this->getCurrentWindowKey($key, $windowSeconds, $now);
        $previousWindow = $this->getPreviousWindowKey($key, $windowSeconds, $now);

        // Increment current window
        $currentResult = $this->driver->increment($currentWindow, $windowSeconds * 2);
        $currentCount = $currentResult['count'];

        // Get previous window count
        $previousCount = $this->driver->get($previousWindow) ?? 0;

        // Calculate weighted count
        $elapsedInWindow = $now % $windowSeconds;
        $overlapPercentage = ($windowSeconds - $elapsedInWindow) / $windowSeconds;
        $weightedCount = (int) ceil(($previousCount * $overlapPercentage) + $currentCount);

        $allowed = $weightedCount <= $limit;
        $remaining = max(0, $limit - $weightedCount);

        // Reset time is end of current window
        $windowStart = $now - $elapsedInWindow;
        $reset = $windowStart + $windowSeconds;

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset' => $reset,
            'limit' => $limit,
        ];
    }

    public function remaining(string $key, int $limit): int
    {
        $now = time();
        $windowSeconds = 60; // Default to minute for remaining check

        $currentWindow = $this->getCurrentWindowKey($key, $windowSeconds, $now);
        $previousWindow = $this->getPreviousWindowKey($key, $windowSeconds, $now);

        $currentCount = $this->driver->get($currentWindow) ?? 0;
        $previousCount = $this->driver->get($previousWindow) ?? 0;

        $elapsedInWindow = $now % $windowSeconds;
        $overlapPercentage = ($windowSeconds - $elapsedInWindow) / $windowSeconds;
        $weightedCount = (int) ceil(($previousCount * $overlapPercentage) + $currentCount);

        return max(0, $limit - $weightedCount);
    }

    public function resetAt(string $key): int
    {
        $windowSeconds = 60;
        $now = time();
        $elapsedInWindow = $now % $windowSeconds;

        return $now + ($windowSeconds - $elapsedInWindow);
    }

    public function reset(string $key): bool
    {
        $now = time();
        $windowSeconds = 60;

        $currentWindow = $this->getCurrentWindowKey($key, $windowSeconds, $now);
        $previousWindow = $this->getPreviousWindowKey($key, $windowSeconds, $now);

        $this->driver->reset($currentWindow);
        $this->driver->reset($previousWindow);

        return true;
    }

    protected function getCurrentWindowKey(string $key, int $windowSeconds, int $now): string
    {
        $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;

        return "{$key}:sliding:{$windowStart}";
    }

    protected function getPreviousWindowKey(string $key, int $windowSeconds, int $now): string
    {
        $windowStart = (int) floor($now / $windowSeconds) * $windowSeconds;
        $previousWindowStart = $windowStart - $windowSeconds;

        return "{$key}:sliding:{$previousWindowStart}";
    }
}

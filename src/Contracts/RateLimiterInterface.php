<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Contracts;

interface RateLimiterInterface
{
    /**
     * Attempt to consume from the rate limiter.
     *
     * @return array{allowed: bool, remaining: int, reset: int, limit: int}
     */
    public function attempt(string $key, int $limit, int $windowSeconds): array;

    /**
     * Get the remaining attempts.
     */
    public function remaining(string $key, int $limit): int;

    /**
     * Get time until reset.
     */
    public function resetAt(string $key): int;

    /**
     * Reset the limiter for a key.
     */
    public function reset(string $key): bool;
}

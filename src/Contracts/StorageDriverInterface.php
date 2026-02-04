<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Contracts;

interface StorageDriverInterface
{
    /**
     * Increment the counter for a given key.
     *
     * @return array{count: int, reset: int}
     */
    public function increment(string $key, int $windowSeconds): array;

    /**
     * Get the current count for a key.
     */
    public function get(string $key): ?int;

    /**
     * Get time until the key resets.
     */
    public function ttl(string $key): int;

    /**
     * Reset/delete a key.
     */
    public function reset(string $key): bool;

    /**
     * Acquire tokens from a bucket (token bucket algorithm).
     *
     * @return array{allowed: bool, tokens: int, reset: int}
     */
    public function acquireToken(string $key, int $maxTokens, float $refillRate): array;

    /**
     * Store quota usage.
     */
    public function incrementQuota(string $key, int $cost = 1): int;

    /**
     * Get quota usage.
     */
    public function getQuota(string $key): int;

    /**
     * Reset quota.
     */
    public function resetQuota(string $key): bool;

    /**
     * Set quota with expiration.
     */
    public function setQuota(string $key, int $value, int $ttl): bool;

    /**
     * Record analytics data.
     */
    public function recordAnalytics(string $key, string $plan, string $endpoint, bool $limited): void;

    /**
     * Get analytics data.
     *
     * @return array<string, mixed>
     */
    public function getAnalytics(string $period = 'day', int $limit = 30): array;

    /**
     * Cleanup old data.
     */
    public function cleanup(int $olderThanDays = 90): int;
}

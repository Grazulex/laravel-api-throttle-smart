<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Contracts;

/**
 * Optional capability for storage drivers that can track in-flight requests.
 *
 * Drivers implementing this interface let the middleware enforce the
 * "concurrent_requests" plan limit. Each acquired slot expires on its own
 * after the given TTL, so a slot leaked by a crashed worker is reclaimed.
 */
interface ConcurrencyStoreInterface
{
    /**
     * Try to acquire a concurrency slot for a key.
     *
     * Returns a slot token when the slot was acquired, or null when the key
     * already holds $limit active slots.
     */
    public function acquireConcurrencySlot(string $key, int $limit, int $ttl): ?string;

    /**
     * Release a slot previously acquired for a key.
     */
    public function releaseConcurrencySlot(string $key, string $token): void;

    /**
     * Count the active (non-expired) slots for a key.
     */
    public function getConcurrentCount(string $key): int;
}

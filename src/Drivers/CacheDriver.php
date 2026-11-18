<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Drivers;

use Closure;
use Grazulex\ThrottleSmart\Contracts\ConcurrencyStoreInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

class CacheDriver implements ConcurrencyStoreInterface, StorageDriverInterface
{
    protected string $prefix;

    protected string $store;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected CacheManager $cache,
        protected array $config,
    ) {
        $this->prefix = $config['prefix'] ?? 'throttle:';
        $this->store = $config['store'] ?? 'default';
    }

    public function increment(string $key, int $windowSeconds): array
    {
        $cache = $this->getCache();
        $fullKey = $this->prefix.$key;

        $count = $cache->increment($fullKey);
        if ($count === 1) {
            $cache->put($fullKey, 1, $windowSeconds);
        }

        $reset = time() + $windowSeconds;

        return [
            'count' => $count,
            'reset' => $reset,
        ];
    }

    public function get(string $key): ?int
    {
        return $this->getCache()->get($this->prefix.$key);
    }

    public function ttl(string $key): int
    {
        // Laravel cache doesn't expose TTL directly
        // Return a default value
        return 60;
    }

    public function reset(string $key): bool
    {
        return $this->getCache()->forget($this->prefix.$key);
    }

    public function acquireToken(string $key, int $maxTokens, float $refillRate): array
    {
        $cache = $this->getCache();
        $fullKey = $this->prefix.'bucket:'.$key;
        $lastRefillKey = $this->prefix.'bucket_time:'.$key;

        $now = microtime(true);
        $lastRefill = $cache->get($lastRefillKey, $now);
        $currentTokens = $cache->get($fullKey, $maxTokens);

        // Calculate tokens to add based on time elapsed
        $elapsed = $now - $lastRefill;
        $tokensToAdd = $elapsed * $refillRate;
        $tokens = min($maxTokens, $currentTokens + $tokensToAdd);

        if ($tokens >= 1) {
            $tokens -= 1;
            $cache->put($fullKey, $tokens, 3600);
            $cache->put($lastRefillKey, $now, 3600);

            return [
                'allowed' => true,
                'tokens' => (int) $tokens,
                'reset' => (int) ($now + (($maxTokens - $tokens) / $refillRate)),
            ];
        }

        return [
            'allowed' => false,
            'tokens' => 0,
            'reset' => (int) ($now + (1 / $refillRate)),
        ];
    }

    public function incrementQuota(string $key, int $cost = 1): int
    {
        $cache = $this->getCache();
        $fullKey = $this->prefix.'quota:'.$key;

        $current = $cache->get($fullKey, 0);
        $new = $current + $cost;
        $cache->put($fullKey, $new, $this->getMonthlyTtl());

        return $new;
    }

    public function getQuota(string $key): int
    {
        return $this->getCache()->get($this->prefix.'quota:'.$key, 0);
    }

    public function resetQuota(string $key): bool
    {
        return $this->getCache()->forget($this->prefix.'quota:'.$key);
    }

    public function setQuota(string $key, int $value, int $ttl): bool
    {
        return $this->getCache()->put($this->prefix.'quota:'.$key, $value, $ttl);
    }

    public function recordAnalytics(string $key, string $plan, string $endpoint, bool $limited): void
    {
        // Analytics recording would typically go to database
        // Cache driver doesn't persist analytics
    }

    public function getAnalytics(string $period = 'day', int $limit = 30): array
    {
        return [];
    }

    public function cleanup(int $olderThanDays = 90): int
    {
        // Cache handles its own expiration
        return 0;
    }

    public function acquireConcurrencySlot(string $key, int $limit, int $ttl): ?string
    {
        $fullKey = $this->prefix.'concurrent:'.$key;
        $token = Str::random(32);
        $ttl = max(1, $ttl);

        return $this->withConcurrencyLock($fullKey, function () use ($fullKey, $limit, $ttl, $token): ?string {
            $cache = $this->getCache();
            $now = now()->getTimestamp();
            $slots = $this->activeSlots($cache->get($fullKey), $now);

            if (count($slots) >= $limit) {
                return null;
            }

            $slots[$token] = $now + $ttl;
            $cache->put($fullKey, $slots, $ttl);

            return $token;
        });
    }

    public function releaseConcurrencySlot(string $key, string $token): void
    {
        $fullKey = $this->prefix.'concurrent:'.$key;

        $this->withConcurrencyLock($fullKey, function () use ($fullKey, $token): null {
            $cache = $this->getCache();
            $now = now()->getTimestamp();
            $slots = $this->activeSlots($cache->get($fullKey), $now);
            unset($slots[$token]);

            if ($slots === []) {
                $cache->forget($fullKey);

                return null;
            }

            $cache->put($fullKey, $slots, max(1, max($slots) - $now));

            return null;
        });
    }

    public function getConcurrentCount(string $key): int
    {
        $slots = $this->getCache()->get($this->prefix.'concurrent:'.$key);

        return count($this->activeSlots($slots, now()->getTimestamp()));
    }

    /**
     * Keep only the slots that have not expired yet.
     *
     * @return array<string, int>
     */
    protected function activeSlots(mixed $slots, int $now): array
    {
        if (! is_array($slots)) {
            return [];
        }

        $active = [];
        foreach ($slots as $token => $expiresAt) {
            if (is_numeric($expiresAt) && (int) $expiresAt > $now) {
                $active[(string) $token] = (int) $expiresAt;
            }
        }

        return $active;
    }

    /**
     * Run a callback under a cache lock when the store supports locks.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function withConcurrencyLock(string $fullKey, Closure $callback): mixed
    {
        $store = $this->getCache()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        try {
            /** @var TResult $result */
            $result = $store->lock($fullKey.':lock', 5)->block(3, $callback);

            return $result;
        } catch (LockTimeoutException) {
            return $callback();
        }
    }

    protected function getCache(): Repository
    {
        return $this->store === 'default'
            ? $this->cache->store()
            : $this->cache->store($this->store);
    }

    protected function getMonthlyTtl(): int
    {
        return (int) now()->diffInSeconds(now()->endOfMonth());
    }
}

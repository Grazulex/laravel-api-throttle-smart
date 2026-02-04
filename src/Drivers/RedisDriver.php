<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Drivers;

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Illuminate\Redis\RedisManager;

class RedisDriver implements StorageDriverInterface
{
    protected string $prefix;

    protected string $connection;

    protected bool $useLua;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected RedisManager $redis,
        protected array $config,
    ) {
        $this->prefix = $config['prefix'] ?? 'throttle:';
        $this->connection = $config['connection'] ?? 'default';
        $this->useLua = $config['use_lua'] ?? true;
    }

    public function increment(string $key, int $windowSeconds): array
    {
        $fullKey = $this->prefix.$key;
        $connection = $this->getConnection();

        if ($this->useLua) {
            // Atomic increment with expiry using Lua script
            $script = <<<'LUA'
                local current = redis.call('incr', KEYS[1])
                if current == 1 then
                    redis.call('expire', KEYS[1], ARGV[1])
                end
                local ttl = redis.call('ttl', KEYS[1])
                return {current, ttl}
            LUA;

            /** @var array{int, int} $result */
            $result = $connection->eval($script, [$fullKey, $windowSeconds], 1);
            $count = $result[0];
            $ttl = $result[1];
        } else {
            $count = $connection->incr($fullKey);
            if ($count === 1) {
                $connection->expire($fullKey, $windowSeconds);
            }
            $ttl = $connection->ttl($fullKey);
        }

        return [
            'count' => (int) $count,
            'reset' => time() + max(0, (int) $ttl),
        ];
    }

    public function get(string $key): ?int
    {
        $value = $this->getConnection()->get($this->prefix.$key);

        return $value !== null ? (int) $value : null;
    }

    public function ttl(string $key): int
    {
        return (int) $this->getConnection()->ttl($this->prefix.$key);
    }

    public function reset(string $key): bool
    {
        return (bool) $this->getConnection()->del($this->prefix.$key);
    }

    public function acquireToken(string $key, int $maxTokens, float $refillRate): array
    {
        $fullKey = $this->prefix.'bucket:'.$key;
        $connection = $this->getConnection();

        if ($this->useLua) {
            // Atomic token bucket using Lua script
            $script = <<<'LUA'
                local key = KEYS[1]
                local max_tokens = tonumber(ARGV[1])
                local refill_rate = tonumber(ARGV[2])
                local now = tonumber(ARGV[3])

                local bucket = redis.call('hmget', key, 'tokens', 'last_refill')
                local tokens = tonumber(bucket[1]) or max_tokens
                local last_refill = tonumber(bucket[2]) or now

                -- Calculate tokens to add
                local elapsed = now - last_refill
                local tokens_to_add = elapsed * refill_rate
                tokens = math.min(max_tokens, tokens + tokens_to_add)

                if tokens >= 1 then
                    tokens = tokens - 1
                    redis.call('hmset', key, 'tokens', tokens, 'last_refill', now)
                    redis.call('expire', key, 3600)
                    local reset = now + ((max_tokens - tokens) / refill_rate)
                    return {1, math.floor(tokens), math.floor(reset)}
                end

                local reset = now + (1 / refill_rate)
                return {0, 0, math.floor(reset)}
            LUA;

            /** @var array{int, int, int} $result */
            $result = $connection->eval($script, [$fullKey, $maxTokens, $refillRate, microtime(true)], 1);

            return [
                'allowed' => (bool) $result[0],
                'tokens' => (int) $result[1],
                'reset' => (int) $result[2],
            ];
        }

        // Non-Lua fallback (not atomic, but functional)
        $now = microtime(true);
        $bucket = $connection->hgetall($fullKey);
        $tokens = isset($bucket['tokens']) ? (float) $bucket['tokens'] : $maxTokens;
        $lastRefill = isset($bucket['last_refill']) ? (float) $bucket['last_refill'] : $now;

        $elapsed = $now - $lastRefill;
        $tokensToAdd = $elapsed * $refillRate;
        $tokens = min($maxTokens, $tokens + $tokensToAdd);

        if ($tokens >= 1) {
            $tokens -= 1;
            $connection->hmset($fullKey, ['tokens' => $tokens, 'last_refill' => $now]);
            $connection->expire($fullKey, 3600);

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
        $fullKey = $this->prefix.'quota:'.$key;
        $connection = $this->getConnection();

        $result = $connection->incrby($fullKey, $cost);

        // Set expiry if this is a new key
        if ($result === $cost) {
            $connection->expireat($fullKey, (int) now()->endOfMonth()->timestamp);
        }

        return (int) $result;
    }

    public function getQuota(string $key): int
    {
        $value = $this->getConnection()->get($this->prefix.'quota:'.$key);

        return $value !== null ? (int) $value : 0;
    }

    public function resetQuota(string $key): bool
    {
        return (bool) $this->getConnection()->del($this->prefix.'quota:'.$key);
    }

    public function setQuota(string $key, int $value, int $ttl): bool
    {
        return (bool) $this->getConnection()->setex($this->prefix.'quota:'.$key, $ttl, $value);
    }

    public function recordAnalytics(string $key, string $plan, string $endpoint, bool $limited): void
    {
        $connection = $this->getConnection();
        $hour = date('Y-m-d-H');
        $analyticsKey = $this->prefix."analytics:{$hour}";

        $connection->hincrby($analyticsKey, 'total_requests', 1);
        $connection->hincrby($analyticsKey, "plan:{$plan}", 1);

        if ($limited) {
            $connection->hincrby($analyticsKey, 'limited_requests', 1);
        }

        // Keep analytics for 30 days
        $connection->expire($analyticsKey, 86400 * 30);
    }

    public function getAnalytics(string $period = 'day', int $limit = 30): array
    {
        $connection = $this->getConnection();
        $analytics = [];

        for ($i = 0; $i < $limit; $i++) {
            $hour = date('Y-m-d-H', strtotime("-{$i} hours"));
            $analyticsKey = $this->prefix."analytics:{$hour}";
            $data = $connection->hgetall($analyticsKey);

            if (! empty($data)) {
                $analytics[$hour] = $data;
            }
        }

        return $analytics;
    }

    public function cleanup(int $olderThanDays = 90): int
    {
        // Redis handles expiration automatically
        return 0;
    }

    protected function getConnection(): \Illuminate\Redis\Connections\Connection
    {
        return $this->redis->connection($this->connection);
    }
}

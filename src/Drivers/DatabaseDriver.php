<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Drivers;

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

class DatabaseDriver implements StorageDriverInterface
{
    protected string $table;

    protected string $quotaTable;

    protected string $analyticsTable;

    protected string $connection;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected DatabaseManager $db,
        protected array $config,
    ) {
        $this->table = $config['table'] ?? 'rate_limits';
        $this->quotaTable = $config['quota_table'] ?? 'api_quotas';
        $this->analyticsTable = $config['analytics_table'] ?? 'api_rate_limit_analytics';
        $this->connection = $config['connection'] ?? config('database.default');
    }

    public function increment(string $key, int $windowSeconds): array
    {
        $now = time();
        $reset = $now + $windowSeconds;

        $result = DB::connection($this->connection)
            ->table($this->table)
            ->updateOrInsert(
                ['key' => $key],
                [
                    'count' => DB::raw('CASE WHEN reset_at <= '.time().' THEN 1 ELSE count + 1 END'),
                    'reset_at' => DB::raw('CASE WHEN reset_at <= '.time().' THEN '.$reset.' ELSE reset_at END'),
                    'updated_at' => now(),
                ]
            );

        $record = DB::connection($this->connection)
            ->table($this->table)
            ->where('key', $key)
            ->first();

        return [
            'count' => $record?->count ?? 1,
            'reset' => $record?->reset_at ?? $reset,
        ];
    }

    public function get(string $key): ?int
    {
        $record = DB::connection($this->connection)
            ->table($this->table)
            ->where('key', $key)
            ->where('reset_at', '>', time())
            ->first();

        return $record?->count;
    }

    public function ttl(string $key): int
    {
        $record = DB::connection($this->connection)
            ->table($this->table)
            ->where('key', $key)
            ->first();

        if (! $record) {
            return 0;
        }

        return max(0, $record->reset_at - time());
    }

    public function reset(string $key): bool
    {
        return DB::connection($this->connection)
            ->table($this->table)
            ->where('key', $key)
            ->delete() > 0;
    }

    public function acquireToken(string $key, int $maxTokens, float $refillRate): array
    {
        // Simplified token bucket for database
        // For high-performance token bucket, use Redis
        $now = microtime(true);
        $bucketKey = 'bucket:'.$key;

        $record = DB::connection($this->connection)
            ->table($this->table)
            ->where('key', $bucketKey)
            ->first();

        $tokens = $maxTokens;
        $lastRefill = $now;

        if ($record) {
            $lastRefill = (float) $record->reset_at;
            $tokens = (float) $record->count;

            $elapsed = $now - $lastRefill;
            $tokensToAdd = $elapsed * $refillRate;
            $tokens = min($maxTokens, $tokens + $tokensToAdd);
        }

        if ($tokens >= 1) {
            $tokens -= 1;

            DB::connection($this->connection)
                ->table($this->table)
                ->updateOrInsert(
                    ['key' => $bucketKey],
                    [
                        'count' => $tokens,
                        'reset_at' => $now,
                        'updated_at' => now(),
                    ]
                );

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
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $monthEnd = now()->endOfMonth()->toDateTimeString();

        $result = DB::connection($this->connection)
            ->table($this->quotaTable)
            ->where('key', $key)
            ->where('period_start', '>=', $monthStart)
            ->first();

        if ($result) {
            DB::connection($this->connection)
                ->table($this->quotaTable)
                ->where('id', $result->id)
                ->increment('used', $cost);

            return $result->used + $cost;
        }

        DB::connection($this->connection)
            ->table($this->quotaTable)
            ->insert([
                'key' => $key,
                'used' => $cost,
                'period_start' => $monthStart,
                'period_end' => $monthEnd,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return $cost;
    }

    public function getQuota(string $key): int
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();

        $result = DB::connection($this->connection)
            ->table($this->quotaTable)
            ->where('key', $key)
            ->where('period_start', '>=', $monthStart)
            ->first();

        return $result?->used ?? 0;
    }

    public function resetQuota(string $key): bool
    {
        return DB::connection($this->connection)
            ->table($this->quotaTable)
            ->where('key', $key)
            ->delete() > 0;
    }

    public function setQuota(string $key, int $value, int $ttl): bool
    {
        $monthStart = now()->startOfMonth()->toDateTimeString();
        $monthEnd = now()->endOfMonth()->toDateTimeString();

        return DB::connection($this->connection)
            ->table($this->quotaTable)
            ->updateOrInsert(
                ['key' => $key, 'period_start' => $monthStart],
                [
                    'used' => $value,
                    'period_end' => $monthEnd,
                    'updated_at' => now(),
                ]
            );
    }

    public function recordAnalytics(string $key, string $plan, string $endpoint, bool $limited): void
    {
        $hour = now()->startOfHour()->toDateTimeString();

        DB::connection($this->connection)
            ->table($this->analyticsTable)
            ->updateOrInsert(
                [
                    'key' => $key,
                    'period' => $hour,
                    'period_type' => 'hour',
                ],
                [
                    'plan' => $plan,
                    'endpoint' => $endpoint,
                    'requests' => DB::raw('requests + 1'),
                    'limited' => DB::raw('limited + '.($limited ? 1 : 0)),
                ]
            );
    }

    public function getAnalytics(string $period = 'day', int $limit = 30): array
    {
        $since = match ($period) {
            'hour' => now()->subHours($limit),
            'day' => now()->subDays($limit),
            'week' => now()->subWeeks($limit),
            default => now()->subDays($limit),
        };

        return DB::connection($this->connection)
            ->table($this->analyticsTable)
            ->where('period', '>=', $since)
            ->orderBy('period', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function cleanup(int $olderThanDays = 90): int
    {
        $cutoff = now()->subDays($olderThanDays)->toDateTimeString();

        $deletedRateLimits = DB::connection($this->connection)
            ->table($this->table)
            ->where('updated_at', '<', $cutoff)
            ->delete();

        $deletedAnalytics = DB::connection($this->connection)
            ->table($this->analyticsTable)
            ->where('period', '<', $cutoff)
            ->delete();

        return $deletedRateLimits + $deletedAnalytics;
    }
}

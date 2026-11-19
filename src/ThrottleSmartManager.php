<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart;

use Carbon\Carbon;
use Closure;
use Grazulex\ThrottleSmart\Contracts\ConcurrencyStoreInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Support\Plan;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

class ThrottleSmartManager
{
    /**
     * Number of bytes in one megabyte, used for "bandwidth_per_day_mb".
     */
    public const int BYTES_PER_MB = 1_048_576;

    protected ?Closure $planResolver = null;

    protected ?Closure $keyResolver = null;

    protected mixed $currentUser = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected StorageDriverInterface $driver,
        protected array $config,
    ) {}

    /**
     * Get rate limits for a user.
     */
    public function getLimits(mixed $user = null): RateLimits
    {
        $user = $user ?? $this->currentUser;
        $plan = $this->resolvePlan($user);
        $planConfig = $this->getPlanConfig($plan);
        $request = $this->currentRequest();
        $override = $this->resolveEndpointOverride($request);

        $limits = [];
        $isLimited = false;
        $limitedBy = null;

        foreach (['second', 'minute', 'hour', 'day'] as $window) {
            $resolved = $this->resolveWindowLimit($user, $window, $planConfig, $override, $request);
            if ($resolved !== null) {
                ['limit' => $limit, 'key' => $key] = $resolved;
                $windowSeconds = $this->getWindowSeconds($window);
                $result = $this->driver->increment($key, $windowSeconds);

                $remaining = max(0, $limit - $result['count']);
                $limits[$window] = [
                    'limit' => $limit,
                    'remaining' => $remaining,
                    'reset' => $result['reset'],
                ];

                if ($result['count'] > $limit && ! $isLimited) {
                    $isLimited = true;
                    $limitedBy = $window;
                }
            }
        }

        return RateLimits::fromArray($plan, $limits, $isLimited, $limitedBy);
    }

    /**
     * Get remaining requests for a specific window.
     */
    public function getRemaining(mixed $user, string $type = 'minute'): int
    {
        $plan = $this->resolvePlan($user);
        $planConfig = $this->getPlanConfig($plan);
        $limit = $planConfig->getLimit($type);

        if ($limit === null) {
            return PHP_INT_MAX; // Unlimited
        }

        $key = $this->buildKey($user, $type);
        $count = $this->driver->get($key) ?? 0;

        return max(0, $limit - $count);
    }

    /**
     * Get quota information for a user.
     */
    public function getQuota(mixed $user = null): QuotaInfo
    {
        $user = $user ?? $this->currentUser;
        $plan = $this->resolvePlan($user);
        $planConfig = $this->getPlanConfig($plan);

        $monthlyLimit = $planConfig->requestsPerMonth;
        $dailyLimit = $planConfig->requestsPerDay;

        $monthlyKey = $this->buildQuotaKey($user, 'month');
        $dailyKey = $this->buildQuotaKey($user, 'day');

        $monthlyUsed = $this->driver->getQuota($monthlyKey);
        $dailyUsed = $this->driver->getQuota($dailyKey);

        $resetsAt = Carbon::now()->startOfMonth()->addMonth();

        return QuotaInfo::fromUsage(
            $monthlyLimit,
            $monthlyUsed,
            $dailyLimit,
            $dailyUsed,
            $resetsAt
        );
    }

    /**
     * Manually consume quota.
     */
    public function consume(int $cost = 1): void
    {
        $user = $this->currentUser ?? request()->user();
        $monthlyKey = $this->buildQuotaKey($user, 'month');
        $dailyKey = $this->buildQuotaKey($user, 'day');

        $this->driver->incrementQuota($monthlyKey, $cost);
        $this->driver->incrementQuota($dailyKey, $cost);
    }

    /**
     * Reset rate limits for a key.
     */
    public function reset(string $key): void
    {
        foreach (['second', 'minute', 'hour', 'day'] as $window) {
            $this->driver->reset("{$key}:{$window}");
        }
    }

    /**
     * Reset quota for a user.
     */
    public function resetQuota(mixed $user): void
    {
        $monthlyKey = $this->buildQuotaKey($user, 'month');
        $dailyKey = $this->buildQuotaKey($user, 'day');

        $this->driver->resetQuota($monthlyKey);
        $this->driver->resetQuota($dailyKey);
    }

    /**
     * Add bonus quota to a user.
     */
    public function addQuota(mixed $user, int $amount, ?string $reason = null): void
    {
        $monthlyKey = $this->buildQuotaKey($user, 'month');
        $currentUsed = $this->driver->getQuota($monthlyKey);

        // Reduce used count (effectively adding quota)
        $newUsed = max(0, $currentUsed - $amount);
        $this->driver->setQuota($monthlyKey, $newUsed, $this->getMonthlyTtl());
    }

    /**
     * Set a temporary limit override.
     */
    public function setTemporaryLimit(mixed $user, string $type, int $limit, Carbon $until): void
    {
        // This would typically be stored in database via a separate mechanism
        // For now, this is a placeholder for the interface
    }

    /**
     * Check if a request would be rate limited (without consuming).
     */
    public function wouldLimit(Request $request): bool
    {
        $user = $request->user();
        $plan = $this->resolvePlan($user);
        $planConfig = $this->getPlanConfig($plan);
        $override = $this->resolveEndpointOverride($request);

        foreach (['second', 'minute', 'hour', 'day'] as $window) {
            $resolved = $this->resolveWindowLimit($user, $window, $planConfig, $override, $request);
            if ($resolved !== null) {
                ['limit' => $limit, 'key' => $key] = $resolved;
                $count = $this->driver->get($key) ?? 0;

                if ($count >= $limit) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record analytics for a request outcome, honoring the "analytics" configuration.
     *
     * The outcome is one of "allowed", "rate_limited" or "quota_exceeded".
     * Storage failures are reported and never interrupt the request.
     */
    public function recordAnalytics(Request $request, string $plan, string $outcome = 'allowed'): void
    {
        if (! $this->shouldTrackAnalytics($outcome)) {
            return;
        }

        try {
            $this->driver->recordAnalytics(
                $this->resolveBaseKey($request->user()),
                $plan,
                $this->resolveAnalyticsEndpoint($request),
                $outcome !== 'allowed',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Try to reserve a concurrency slot for the plan "concurrent_requests" limit.
     *
     * "allowed" is false when the limit is reached. "token" is null when no slot
     * was reserved: the plan has no limit, the driver cannot track concurrency,
     * or the storage failed (failures are reported and the request is allowed).
     * Release a reserved slot with releaseConcurrencySlot() once the request ends.
     *
     * @return array{allowed: bool, limit: int|null, key: string|null, token: string|null}
     */
    public function acquireConcurrencySlot(mixed $user = null): array
    {
        $user = $user ?? $this->currentUser;
        $limit = $this->getPlanConfig($this->resolvePlan($user))->concurrentRequests;

        if ($limit === null || $limit < 1 || ! $this->driver instanceof ConcurrencyStoreInterface) {
            return ['allowed' => true, 'limit' => $limit, 'key' => null, 'token' => null];
        }

        $key = $this->buildKey($user, 'concurrent');

        try {
            $token = $this->driver->acquireConcurrencySlot($key, $limit, $this->getConcurrencyTtl());
        } catch (Throwable $e) {
            report($e);

            return ['allowed' => true, 'limit' => $limit, 'key' => null, 'token' => null];
        }

        if ($token === null) {
            return ['allowed' => false, 'limit' => $limit, 'key' => $key, 'token' => null];
        }

        return ['allowed' => true, 'limit' => $limit, 'key' => $key, 'token' => $token];
    }

    /**
     * Release a concurrency slot reserved by acquireConcurrencySlot().
     *
     * Storage failures are reported; the slot then expires after its TTL.
     */
    public function releaseConcurrencySlot(string $key, string $token): void
    {
        if (! $this->driver instanceof ConcurrencyStoreInterface) {
            return;
        }

        try {
            $this->driver->releaseConcurrencySlot($key, $token);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Get the number of in-flight requests for a user (0 when not tracked).
     */
    public function getConcurrentRequests(mixed $user = null): int
    {
        if (! $this->driver instanceof ConcurrencyStoreInterface) {
            return 0;
        }

        return $this->driver->getConcurrentCount($this->buildKey($user ?? $this->currentUser, 'concurrent'));
    }

    /**
     * Get the safety TTL (seconds) after which an unreleased slot expires.
     */
    public function getConcurrencyTtl(): int
    {
        $concurrency = $this->config['concurrency'] ?? [];
        $ttl = is_array($concurrency) ? ($concurrency['ttl'] ?? 60) : 60;

        return is_numeric($ttl) ? max(1, (int) $ttl) : 60;
    }

    /**
     * Get today's bandwidth usage for the plan "bandwidth_per_day_mb" limit.
     *
     * Sizes are in bytes. "limit", "remaining" and "key" are null when the plan
     * has no bandwidth limit. Storage failures are reported and the usage is
     * then considered to be 0. Add the size of a response to the counter with
     * consumeBandwidth() using the returned "key".
     *
     * @return array{limit: int|null, used: int, remaining: int|null, exceeded: bool, key: string|null, resets_at: Carbon}
     */
    public function getBandwidth(mixed $user = null): array
    {
        $user = $user ?? $this->currentUser;
        $limitMb = $this->getPlanConfig($this->resolvePlan($user))->bandwidthPerDayMb;
        $resetsAt = Carbon::now()->addDay()->startOfDay();

        if ($limitMb === null || $limitMb < 1) {
            return [
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'exceeded' => false,
                'key' => null,
                'resets_at' => $resetsAt,
            ];
        }

        $limit = $limitMb * self::BYTES_PER_MB;
        $key = $this->buildBandwidthKey($user);

        try {
            $used = $this->driver->getQuota($key);
        } catch (Throwable $e) {
            report($e);
            $used = 0;
        }

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'exceeded' => $used >= $limit,
            'key' => $key,
            'resets_at' => $resetsAt,
        ];
    }

    /**
     * Add bytes to a daily bandwidth counter returned by getBandwidth().
     *
     * Storage failures are reported and never interrupt the request.
     */
    public function consumeBandwidth(string $key, int $bytes): void
    {
        if ($bytes < 1) {
            return;
        }

        try {
            $this->driver->incrementQuota($key, $bytes);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Determine whether analytics should be recorded for an outcome.
     */
    public function shouldTrackAnalytics(string $outcome = 'allowed'): bool
    {
        $analytics = $this->config['analytics'] ?? [];

        if (! is_array($analytics) || ! (bool) ($analytics['enabled'] ?? false)) {
            return false;
        }

        $option = match ($outcome) {
            'rate_limited' => 'limited',
            'quota_exceeded' => 'quota_usage',
            default => 'requests',
        };

        $track = is_array($analytics['track'] ?? null) ? $analytics['track'] : [];

        return (bool) ($track[$option] ?? true);
    }

    /**
     * Check if a request is bypassed from rate limiting.
     */
    public function isBypassed(Request $request): bool
    {
        $bypassConfig = $this->config['bypass'] ?? [];

        // Check IP bypass
        $bypassIps = $bypassConfig['ips'] ?? [];
        if (in_array($request->ip(), $bypassIps, true)) {
            return true;
        }

        // Check API key bypass
        $bypassApiKeys = $bypassConfig['api_keys'] ?? [];
        $apiKey = $request->header('X-API-Key') ?? $request->header('Authorization');
        if ($apiKey && in_array($apiKey, $bypassApiKeys, true)) {
            return true;
        }

        // Check user bypass
        $user = $request->user();
        if ($user) {
            $userBypass = $bypassConfig['users'] ?? [];
            $bypassIds = $userBypass['ids'] ?? [];
            if (in_array($user->id, $bypassIds, true)) {
                return true;
            }

            $bypassAttribute = $userBypass['attribute'] ?? null;
            if ($bypassAttribute && ($user->{$bypassAttribute} ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set a custom plan resolver.
     */
    public function resolvePlanUsing(Closure $callback): self
    {
        $this->planResolver = $callback;

        return $this;
    }

    /**
     * Set a custom key resolver.
     */
    public function resolveKeyUsing(Closure $callback): self
    {
        $this->keyResolver = $callback;

        return $this;
    }

    /**
     * Set the user context for subsequent operations.
     */
    public function forUser(mixed $user): self
    {
        $this->currentUser = $user;

        return $this;
    }

    /**
     * Resolve the plan for a user.
     */
    protected function resolvePlan(mixed $user): string
    {
        if ($this->planResolver) {
            return ($this->planResolver)(request());
        }

        if (! $user) {
            return $this->config['default_plan'] ?? 'free';
        }

        $resolverConfig = $this->config['plan_resolver'] ?? [];
        $type = $resolverConfig['type'] ?? 'attribute';

        return match ($type) {
            'attribute' => $user->{$resolverConfig['attribute'] ?? 'plan'} ?? $this->config['default_plan'] ?? 'free',
            'method' => $user->{$resolverConfig['method'] ?? 'getApiPlan'}() ?? $this->config['default_plan'] ?? 'free',
            'callback' => ($resolverConfig['callback'])($user) ?? $this->config['default_plan'] ?? 'free',
            default => $this->config['default_plan'] ?? 'free',
        };
    }

    /**
     * Get the plan configuration.
     */
    protected function getPlanConfig(string $plan): Plan
    {
        $plans = $this->config['plans'] ?? [];
        $planConfig = $plans[$plan] ?? $plans[$this->config['default_plan'] ?? 'free'] ?? [];

        return Plan::fromConfig($plan, $planConfig);
    }

    /**
     * Build the rate limit key.
     */
    protected function buildKey(mixed $user, string $window): string
    {
        $baseKey = $this->resolveBaseKey($user);
        $prefix = $this->getKeyPrefix();

        return "{$prefix}{$baseKey}:{$window}";
    }

    /**
     * Resolve the base key (scope) used for plan-level counters.
     */
    protected function resolveBaseKey(mixed $user): string
    {
        if ($this->keyResolver) {
            return (string) ($this->keyResolver)(request());
        }

        if ($user) {
            return "user:{$user->id}";
        }

        return 'ip:'.request()->ip();
    }

    /**
     * Get the storage key prefix.
     */
    protected function getKeyPrefix(): string
    {
        return $this->config['drivers'][$this->config['driver'] ?? 'cache']['prefix'] ?? 'throttle:';
    }

    /**
     * Get the current HTTP request, if any.
     */
    protected function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        return request();
    }

    /**
     * Resolve the limit and counter key for a window, applying any endpoint override.
     *
     * @param  array{pattern: string, config: array<string, mixed>}|null  $override
     * @return array{limit: int, key: string}|null
     */
    protected function resolveWindowLimit(mixed $user, string $window, Plan $planConfig, ?array $override, ?Request $request): ?array
    {
        if ($override !== null) {
            $endpointLimit = $this->getEndpointLimit($override['config'], $window);

            if ($endpointLimit !== null) {
                return [
                    'limit' => $endpointLimit,
                    'key' => $this->buildEndpointKey($user, $window, $override, $request),
                ];
            }
        }

        $limit = $planConfig->getLimit($window);

        if ($limit === null) {
            return null;
        }

        return ['limit' => $limit, 'key' => $this->buildKey($user, $window)];
    }

    /**
     * Find the first endpoint override (config "endpoints") matching the request.
     *
     * @return array{pattern: string, config: array<string, mixed>}|null
     */
    protected function resolveEndpointOverride(?Request $request): ?array
    {
        $endpoints = $this->config['endpoints'] ?? [];

        if (! $request instanceof Request || ! is_array($endpoints) || $endpoints === []) {
            return null;
        }

        $method = strtoupper($request->method());
        $path = trim($request->path(), '/');

        foreach ($endpoints as $pattern => $override) {
            if (! is_string($pattern) || ! is_array($override)) {
                continue;
            }

            if ($this->endpointMatches($pattern, $method, $path)) {
                return ['pattern' => $pattern, 'config' => $override];
            }
        }

        return null;
    }

    /**
     * Check whether an endpoint pattern ("METHOD /path/*" or "/path/*") matches.
     */
    protected function endpointMatches(string $pattern, string $method, string $path): bool
    {
        $pattern = trim($pattern);

        if (preg_match('/^(\S+)\s+(\S+)$/', $pattern, $matches) === 1) {
            $methodPattern = $matches[1];
            $pathPattern = $matches[2];
        } else {
            $methodPattern = '*';
            $pathPattern = $pattern;
        }

        $methods = array_map(strtoupper(...), explode('|', $methodPattern));
        if (! Str::is($methods, $method)) {
            return false;
        }

        return Str::is(trim($pathPattern, '/'), $path);
    }

    /**
     * Get an endpoint override limit for a window, or null when not overridden.
     *
     * @param  array<string, mixed>  $override
     */
    protected function getEndpointLimit(array $override, string $window): ?int
    {
        $value = $override["requests_per_{$window}"] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Build the rate limit key for an endpoint override counter.
     *
     * @param  array{pattern: string, config: array<string, mixed>}  $override
     */
    protected function buildEndpointKey(mixed $user, string $window, array $override, ?Request $request): string
    {
        $scope = $override['config']['scope'] ?? null;
        $ip = 'ip:'.($request?->ip() ?? request()->ip());

        $baseKey = match ($scope) {
            'ip' => $ip,
            'user' => $user ? "user:{$user->id}" : $ip,
            default => $this->resolveBaseKey($user),
        };

        $prefix = $this->getKeyPrefix();
        $endpoint = md5($override['pattern']);

        return "{$prefix}{$baseKey}:endpoint:{$endpoint}:{$window}";
    }

    /**
     * Resolve the endpoint label used for analytics ("METHOD /route/uri").
     */
    protected function resolveAnalyticsEndpoint(Request $request): string
    {
        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : $request->path();

        return strtoupper($request->method()).' /'.ltrim($uri, '/');
    }

    /**
     * Build the daily bandwidth counter key (one counter per scope and day).
     */
    protected function buildBandwidthKey(mixed $user): string
    {
        $baseKey = $this->resolveBaseKey($user);
        $prefix = $this->getKeyPrefix();

        return "{$prefix}bandwidth:{$baseKey}:".Carbon::now()->format('Y-m-d');
    }

    /**
     * Build the quota key.
     */
    protected function buildQuotaKey(mixed $user, string $type): string
    {
        $userId = $user?->id ?? 'anonymous';
        $prefix = $this->config['drivers'][$this->config['driver'] ?? 'cache']['prefix'] ?? 'throttle:';

        return "{$prefix}quota:{$userId}:{$type}";
    }

    /**
     * Get window duration in seconds.
     */
    protected function getWindowSeconds(string $window): int
    {
        return match ($window) {
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            default => 60,
        };
    }

    /**
     * Get TTL for monthly quota.
     */
    protected function getMonthlyTtl(): int
    {
        return (int) Carbon::now()->diffInSeconds(Carbon::now()->endOfMonth());
    }
}

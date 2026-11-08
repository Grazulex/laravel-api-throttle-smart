<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart;

use Carbon\Carbon;
use Closure;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Support\Plan;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ThrottleSmartManager
{
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

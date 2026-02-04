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

        $limits = [];
        $isLimited = false;
        $limitedBy = null;

        foreach (['second', 'minute', 'hour', 'day'] as $window) {
            $limit = $planConfig->getLimit($window);
            if ($limit !== null) {
                $key = $this->buildKey($user, $window);
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

        foreach (['second', 'minute', 'hour', 'day'] as $window) {
            $limit = $planConfig->getLimit($window);
            if ($limit !== null) {
                $key = $this->buildKey($user, $window);
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
        if ($this->keyResolver) {
            $baseKey = ($this->keyResolver)(request());
        } elseif ($user) {
            $baseKey = "user:{$user->id}";
        } else {
            $baseKey = 'ip:'.request()->ip();
        }

        $prefix = $this->config['drivers'][$this->config['driver'] ?? 'cache']['prefix'] ?? 'throttle:';

        return "{$prefix}{$baseKey}:{$window}";
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

<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Testing;

use Carbon\Carbon;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Illuminate\Http\Request;
use PHPUnit\Framework\Assert;

class ThrottleSmartFake
{
    /**
     * @var array<string, array<string, int>>
     */
    protected array $limits = [];

    /**
     * @var array<string, array<string, int>>
     */
    protected array $quotas = [];

    /**
     * @var array<string, bool>
     */
    protected array $exhausted = [];

    /**
     * @var array<string, int>
     */
    protected array $consumed = [];

    public function getLimits(mixed $user = null): RateLimits
    {
        $key = $this->getUserKey($user);
        $plan = 'fake';
        $isLimited = $this->exhausted[$key] ?? false;

        return RateLimits::fromArray(
            $plan,
            $this->limits[$key] ?? [
                'minute' => ['limit' => 60, 'remaining' => 60, 'reset' => time() + 60],
            ],
            $isLimited,
            $isLimited ? 'minute' : null
        );
    }

    public function getRemaining(mixed $user, string $type = 'minute'): int
    {
        $key = $this->getUserKey($user);

        return $this->limits[$key][$type]['remaining'] ?? 60;
    }

    public function getQuota(mixed $user = null): QuotaInfo
    {
        $key = $this->getUserKey($user);
        $quota = $this->quotas[$key] ?? [];

        return QuotaInfo::fromUsage(
            $quota['monthly_limit'] ?? 100000,
            $quota['monthly_used'] ?? 0,
            $quota['daily_limit'] ?? null,
            $quota['daily_used'] ?? 0,
            Carbon::now()->endOfMonth()
        );
    }

    public function consume(int $cost = 1): void
    {
        $key = 'current';
        $this->consumed[$key] = ($this->consumed[$key] ?? 0) + $cost;
    }

    public function reset(string $key): void
    {
        unset($this->limits[$key], $this->exhausted[$key]);
    }

    public function resetQuota(mixed $user): void
    {
        $key = $this->getUserKey($user);
        unset($this->quotas[$key]);
    }

    public function addQuota(mixed $user, int $amount, ?string $reason = null): void
    {
        $key = $this->getUserKey($user);
        if (! isset($this->quotas[$key])) {
            $this->quotas[$key] = ['monthly_used' => 0];
        }
        $this->quotas[$key]['monthly_used'] = max(0, $this->quotas[$key]['monthly_used'] - $amount);
    }

    public function setTemporaryLimit(mixed $user, string $type, int $limit, Carbon $until): void
    {
        $key = $this->getUserKey($user);
        if (! isset($this->limits[$key])) {
            $this->limits[$key] = [];
        }
        $this->limits[$key][$type] = [
            'limit' => $limit,
            'remaining' => $limit,
            'reset' => $until->timestamp,
        ];
    }

    public function wouldLimit(Request $request): bool
    {
        return false;
    }

    public function isBypassed(Request $request): bool
    {
        return false;
    }

    // Test helper methods

    /**
     * Set a specific limit for a user.
     */
    public function setLimitFor(mixed $user, string $type, int $limit): self
    {
        $key = $this->getUserKey($user);
        if (! isset($this->limits[$key])) {
            $this->limits[$key] = [];
        }
        $this->limits[$key][$type] = [
            'limit' => $limit,
            'remaining' => $limit,
            'reset' => time() + 60,
        ];

        return $this;
    }

    /**
     * Exhaust a limit for a user.
     */
    public function exhaustLimit(mixed $user, string $type): self
    {
        $key = $this->getUserKey($user);
        $this->exhausted[$key] = true;

        if (! isset($this->limits[$key])) {
            $this->limits[$key] = [];
        }
        $this->limits[$key][$type] = [
            'limit' => 60,
            'remaining' => 0,
            'reset' => time() + 60,
        ];

        return $this;
    }

    /**
     * Set quota usage for a user.
     */
    public function setQuotaUsed(mixed $user, string $type, int $used): self
    {
        $key = $this->getUserKey($user);
        if (! isset($this->quotas[$key])) {
            $this->quotas[$key] = [];
        }
        $this->quotas[$key]["{$type}_used"] = $used;

        return $this;
    }

    // Assertions

    public function assertLimited(mixed $user): void
    {
        $key = $this->getUserKey($user);
        Assert::assertTrue(
            $this->exhausted[$key] ?? false,
            'Expected user to be rate limited, but they are not.'
        );
    }

    public function assertNotLimited(mixed $user): void
    {
        $key = $this->getUserKey($user);
        Assert::assertFalse(
            $this->exhausted[$key] ?? false,
            'Expected user to not be rate limited, but they are.'
        );
    }

    public function assertLimitedBy(mixed $user, string $type): void
    {
        $key = $this->getUserKey($user);
        Assert::assertTrue(
            $this->exhausted[$key] ?? false,
            "Expected user to be rate limited by {$type}, but they are not limited."
        );
    }

    public function assertQuotaConsumed(mixed $user, int $expected): void
    {
        $key = $this->getUserKey($user);
        $consumed = $this->consumed[$key] ?? 0;
        Assert::assertEquals(
            $expected,
            $consumed,
            "Expected {$expected} quota consumed, but got {$consumed}."
        );
    }

    public function assertQuotaRemaining(mixed $user, string $type, int $expected): void
    {
        $key = $this->getUserKey($user);
        $quota = $this->quotas[$key] ?? [];
        $limit = $quota["{$type}_limit"] ?? 100000;
        $used = $quota["{$type}_used"] ?? 0;
        $remaining = $limit - $used;

        Assert::assertEquals(
            $expected,
            $remaining,
            "Expected {$expected} quota remaining, but got {$remaining}."
        );
    }

    protected function getUserKey(mixed $user): string
    {
        if ($user === null) {
            return 'anonymous';
        }

        if (is_object($user) && property_exists($user, 'id')) {
            return "user:{$user->id}";
        }

        return 'user:'.((string) $user);
    }
}

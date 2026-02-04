<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Support;

use Carbon\Carbon;

class QuotaInfo
{
    /**
     * @param  array{limit: int|null, used: int, remaining: int|null}|null  $monthly
     * @param  array{limit: int|null, used: int, remaining: int|null}|null  $daily
     */
    public function __construct(
        public readonly ?array $monthly = null,
        public readonly ?array $daily = null,
        public readonly ?Carbon $resetsAt = null,
        public readonly float $percentageUsed = 0.0,
        public readonly bool $isExceeded = false,
    ) {}

    /**
     * Create from usage data.
     */
    public static function fromUsage(
        ?int $monthlyLimit,
        int $monthlyUsed,
        ?int $dailyLimit,
        int $dailyUsed,
        ?Carbon $resetsAt = null,
    ): self {
        $monthly = null;
        $daily = null;
        $isExceeded = false;
        $percentageUsed = 0.0;

        if ($monthlyLimit !== null) {
            $monthlyRemaining = max(0, $monthlyLimit - $monthlyUsed);
            $monthly = [
                'limit' => $monthlyLimit,
                'used' => $monthlyUsed,
                'remaining' => $monthlyRemaining,
            ];
            $percentageUsed = ($monthlyLimit > 0) ? ($monthlyUsed / $monthlyLimit) * 100 : 0;
            $isExceeded = $monthlyUsed >= $monthlyLimit;
        }

        if ($dailyLimit !== null) {
            $dailyRemaining = max(0, $dailyLimit - $dailyUsed);
            $daily = [
                'limit' => $dailyLimit,
                'used' => $dailyUsed,
                'remaining' => $dailyRemaining,
            ];
            if ($dailyUsed >= $dailyLimit) {
                $isExceeded = true;
            }
        }

        return new self(
            monthly: $monthly,
            daily: $daily,
            resetsAt: $resetsAt,
            percentageUsed: $percentageUsed,
            isExceeded: $isExceeded,
        );
    }

    /**
     * Get the remaining quota (monthly or daily, whichever is more restrictive).
     */
    public function getRemaining(): ?int
    {
        $monthlyRemaining = $this->monthly['remaining'] ?? null;
        $dailyRemaining = $this->daily['remaining'] ?? null;

        if ($monthlyRemaining === null && $dailyRemaining === null) {
            return null;
        }

        if ($monthlyRemaining === null) {
            return $dailyRemaining;
        }

        if ($dailyRemaining === null) {
            return $monthlyRemaining;
        }

        return min($monthlyRemaining, $dailyRemaining);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'monthly' => $this->monthly,
            'daily' => $this->daily,
            'resets_at' => $this->resetsAt?->toIso8601String(),
            'percentage_used' => round($this->percentageUsed, 2),
            'is_exceeded' => $this->isExceeded,
        ];
    }
}

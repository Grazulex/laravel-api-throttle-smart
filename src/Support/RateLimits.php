<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Support;

class RateLimits
{
    /**
     * @param  array{limit: int, remaining: int, reset: int}|null  $perSecond
     * @param  array{limit: int, remaining: int, reset: int}|null  $perMinute
     * @param  array{limit: int, remaining: int, reset: int}|null  $perHour
     * @param  array{limit: int, remaining: int, reset: int}|null  $perDay
     */
    public function __construct(
        public readonly string $plan,
        public readonly ?array $perSecond = null,
        public readonly ?array $perMinute = null,
        public readonly ?array $perHour = null,
        public readonly ?array $perDay = null,
        public readonly bool $isLimited = false,
        public readonly ?string $limitedBy = null,
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * Get the most restrictive limit info for headers.
     *
     * @return array{limit: int, remaining: int, reset: int}
     */
    public function getPrimaryLimit(): array
    {
        // Return the most restrictive active limit (minute is default)
        return $this->perMinute ?? $this->perSecond ?? $this->perHour ?? $this->perDay ?? [
            'limit' => 0,
            'remaining' => 0,
            'reset' => time(),
        ];
    }

    /**
     * Create from array of limit results.
     *
     * @param  array<string, array{limit: int, remaining: int, reset: int}>  $limits
     */
    public static function fromArray(string $plan, array $limits, bool $isLimited = false, ?string $limitedBy = null): self
    {
        $retryAfter = null;
        if ($isLimited && $limitedBy && isset($limits[$limitedBy])) {
            $retryAfter = max(0, $limits[$limitedBy]['reset'] - time());
        }

        return new self(
            plan: $plan,
            perSecond: $limits['second'] ?? null,
            perMinute: $limits['minute'] ?? null,
            perHour: $limits['hour'] ?? null,
            perDay: $limits['day'] ?? null,
            isLimited: $isLimited,
            limitedBy: $limitedBy,
            retryAfter: $retryAfter,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'per_second' => $this->perSecond,
            'per_minute' => $this->perMinute,
            'per_hour' => $this->perHour,
            'per_day' => $this->perDay,
            'is_limited' => $this->isLimited,
            'limited_by' => $this->limitedBy,
            'retry_after' => $this->retryAfter,
        ];
    }
}

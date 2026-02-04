<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Support;

class Plan
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $label = null,
        public readonly ?int $requestsPerSecond = null,
        public readonly ?int $requestsPerMinute = null,
        public readonly ?int $requestsPerHour = null,
        public readonly ?int $requestsPerDay = null,
        public readonly ?int $requestsPerMonth = null,
        public readonly int $burstSize = 0,
        public readonly float $burstRefillRate = 1.0,
        public readonly ?int $concurrentRequests = null,
        public readonly ?int $bandwidthPerDayMb = null,
    ) {}

    /**
     * Create from config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            name: $name,
            label: $config['label'] ?? ucfirst($name).' Plan',
            requestsPerSecond: $config['requests_per_second'] ?? null,
            requestsPerMinute: $config['requests_per_minute'] ?? null,
            requestsPerHour: $config['requests_per_hour'] ?? null,
            requestsPerDay: $config['requests_per_day'] ?? null,
            requestsPerMonth: $config['requests_per_month'] ?? null,
            burstSize: $config['burst_size'] ?? 0,
            burstRefillRate: $config['burst_refill_rate'] ?? 1.0,
            concurrentRequests: $config['concurrent_requests'] ?? null,
            bandwidthPerDayMb: $config['bandwidth_per_day_mb'] ?? null,
        );
    }

    /**
     * Get the limit for a given window type.
     */
    public function getLimit(string $windowType): ?int
    {
        return match ($windowType) {
            'second' => $this->requestsPerSecond,
            'minute' => $this->requestsPerMinute,
            'hour' => $this->requestsPerHour,
            'day' => $this->requestsPerDay,
            'month' => $this->requestsPerMonth,
            default => null,
        };
    }

    /**
     * Check if this plan has unlimited requests for a window.
     */
    public function isUnlimited(string $windowType): bool
    {
        return $this->getLimit($windowType) === null;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'requests_per_second' => $this->requestsPerSecond,
            'requests_per_minute' => $this->requestsPerMinute,
            'requests_per_hour' => $this->requestsPerHour,
            'requests_per_day' => $this->requestsPerDay,
            'requests_per_month' => $this->requestsPerMonth,
            'burst_size' => $this->burstSize,
            'burst_refill_rate' => $this->burstRefillRate,
            'concurrent_requests' => $this->concurrentRequests,
            'bandwidth_per_day_mb' => $this->bandwidthPerDayMb,
        ];
    }
}

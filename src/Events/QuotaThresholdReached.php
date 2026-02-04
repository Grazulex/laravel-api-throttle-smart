<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Events;

use Illuminate\Foundation\Auth\User;

class QuotaThresholdReached
{
    public function __construct(
        public readonly User $user,
        public readonly string $quotaType,
        public readonly int $limit,
        public readonly int $used,
        public readonly float $percentage,
        public readonly string $threshold,
    ) {}
}

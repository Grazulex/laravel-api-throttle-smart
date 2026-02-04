<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Events;

use Illuminate\Foundation\Auth\User;

class RateLimitApproaching
{
    public function __construct(
        public readonly string $key,
        public readonly string $plan,
        public readonly string $limitType,
        public readonly int $limit,
        public readonly int $used,
        public readonly float $percentage,
        public readonly ?User $user,
    ) {}
}

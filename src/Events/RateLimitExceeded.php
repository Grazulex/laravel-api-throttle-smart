<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Events;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;

class RateLimitExceeded
{
    public function __construct(
        public readonly string $key,
        public readonly string $plan,
        public readonly string $limitType,
        public readonly int $limit,
        public readonly int $attempted,
        public readonly ?User $user,
        public readonly Request $request,
        public readonly int $retryAfter,
    ) {}
}

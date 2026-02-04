<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Events;

use Carbon\Carbon;
use Illuminate\Foundation\Auth\User;

class QuotaExceeded
{
    public function __construct(
        public readonly User $user,
        public readonly string $quotaType,
        public readonly int $limit,
        public readonly int $used,
        public readonly ?Carbon $resetsAt = null,
    ) {}
}

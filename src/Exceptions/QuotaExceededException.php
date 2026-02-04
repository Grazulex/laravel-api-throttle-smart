<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Exceptions;

use Carbon\Carbon;

class QuotaExceededException extends ThrottleSmartException
{
    public function __construct(
        public readonly string $quotaType,
        public readonly int $limit,
        public readonly int $used,
        public readonly ?Carbon $resetsAt = null,
    ) {
        $message = sprintf('Quota exceeded for %s. Used %d of %d.', $quotaType, $used, $limit);
        if ($resetsAt) {
            $message .= sprintf(' Resets at %s.', $resetsAt->toIso8601String());
        }
        parent::__construct($message);
    }
}

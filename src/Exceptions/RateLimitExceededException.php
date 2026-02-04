<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Exceptions;

class RateLimitExceededException extends ThrottleSmartException
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $limit,
        public readonly int $retryAfter,
        public readonly ?string $plan = null,
    ) {
        parent::__construct(
            sprintf('Rate limit exceeded for %s. Retry after %d seconds.', $limitType, $retryAfter)
        );
    }
}

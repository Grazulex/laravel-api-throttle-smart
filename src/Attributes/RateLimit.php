<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RateLimit
{
    public function __construct(
        public ?int $perSecond = null,
        public ?int $perMinute = null,
        public ?int $perHour = null,
        public ?int $perDay = null,
        public ?string $scope = null,
        public bool $bypass = false,
    ) {}
}

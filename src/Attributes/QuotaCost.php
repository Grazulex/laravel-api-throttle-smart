<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class QuotaCost
{
    public function __construct(
        public int $cost = 1,
    ) {}
}

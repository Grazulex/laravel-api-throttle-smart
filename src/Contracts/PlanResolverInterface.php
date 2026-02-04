<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Contracts;

use Illuminate\Http\Request;

interface PlanResolverInterface
{
    /**
     * Resolve the plan for the given request.
     */
    public function resolve(Request $request): string;
}

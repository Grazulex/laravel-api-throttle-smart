<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Contracts;

use Illuminate\Http\Request;

interface KeyResolverInterface
{
    /**
     * Resolve the rate limit key for the given request.
     */
    public function resolve(Request $request): string;

    /**
     * Get the scope type (user, team, tenant, ip, api_key).
     */
    public function getScopeType(): string;
}

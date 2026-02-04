<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Grazulex\ThrottleSmart\ThrottleSmartManager;

if (! function_exists('throttle_smart')) {
    /**
     * Get the ThrottleSmart manager instance.
     */
    function throttle_smart(): ThrottleSmartManager
    {
        return app(ThrottleSmartManager::class);
    }
}

if (! function_exists('rate_limits')) {
    /**
     * Get rate limits for the current user.
     */
    function rate_limits(mixed $user = null): RateLimits
    {
        return throttle_smart()->getLimits($user);
    }
}

if (! function_exists('api_quota')) {
    /**
     * Get quota info for the current user.
     */
    function api_quota(mixed $user = null): QuotaInfo
    {
        return throttle_smart()->getQuota($user);
    }
}

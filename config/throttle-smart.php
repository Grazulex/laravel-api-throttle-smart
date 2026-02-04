<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Throttling
    |--------------------------------------------------------------------------
    */
    'enabled' => env('THROTTLE_SMART_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Driver
    |--------------------------------------------------------------------------
    | Supported: "redis", "cache", "database"
    | Redis is strongly recommended for production.
    */
    'driver' => env('THROTTLE_SMART_DRIVER', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Driver Configuration
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'redis' => [
            'connection' => env('THROTTLE_SMART_REDIS_CONNECTION', 'default'),
            'prefix' => 'throttle:',
            'use_lua' => true,
        ],
        'cache' => [
            'store' => env('THROTTLE_SMART_CACHE_STORE', 'default'),
            'prefix' => 'throttle:',
        ],
        'database' => [
            'connection' => env('DB_CONNECTION'),
            'table' => 'rate_limits',
            'quota_table' => 'api_quotas',
            'analytics_table' => 'api_rate_limit_analytics',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan Definitions
    |--------------------------------------------------------------------------
    | Define rate limits and quotas for each plan.
    | null = unlimited
    */
    'plans' => [
        'free' => [
            'label' => 'Free Plan',
            'requests_per_second' => 5,
            'requests_per_minute' => 60,
            'requests_per_hour' => 500,
            'requests_per_day' => 5000,
            'requests_per_month' => 100000,
            'burst_size' => 10,
            'burst_refill_rate' => 1,
            'concurrent_requests' => 5,
            'bandwidth_per_day_mb' => 100,
        ],

        'pro' => [
            'label' => 'Pro Plan',
            'requests_per_second' => 20,
            'requests_per_minute' => 300,
            'requests_per_hour' => 5000,
            'requests_per_day' => 50000,
            'requests_per_month' => 1000000,
            'burst_size' => 50,
            'burst_refill_rate' => 5,
            'concurrent_requests' => 20,
            'bandwidth_per_day_mb' => 1000,
        ],

        'enterprise' => [
            'label' => 'Enterprise Plan',
            'requests_per_second' => 100,
            'requests_per_minute' => 1000,
            'requests_per_hour' => 20000,
            'requests_per_day' => 200000,
            'requests_per_month' => null,
            'burst_size' => 200,
            'burst_refill_rate' => 20,
            'concurrent_requests' => 100,
            'bandwidth_per_day_mb' => null,
        ],

        'internal' => [
            'label' => 'Internal Services',
            'requests_per_minute' => null,
            'requests_per_month' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Plan
    |--------------------------------------------------------------------------
    | Plan to use when user has no plan assigned or for unauthenticated requests.
    */
    'default_plan' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Plan Resolver
    |--------------------------------------------------------------------------
    | How to determine a user's plan.
    | Options: 'attribute', 'method', 'callback'
    */
    'plan_resolver' => [
        'type' => 'attribute',
        'attribute' => 'plan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Resolver (Scoping)
    |--------------------------------------------------------------------------
    | How to scope rate limits.
    */
    'key_resolver' => [
        'primary' => 'user',
        'fallback' => 'ip',
        'include_route' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Endpoint Overrides
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'POST /api/*/login' => [
            'requests_per_minute' => 5,
            'requests_per_hour' => 20,
            'scope' => 'ip',
        ],
        'POST /api/*/register' => [
            'requests_per_minute' => 3,
            'requests_per_hour' => 10,
            'scope' => 'ip',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sliding Window Configuration
    |--------------------------------------------------------------------------
    */
    'sliding_window' => [
        'enabled' => true,
        'precision' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Bucket (Burst) Configuration
    |--------------------------------------------------------------------------
    */
    'token_bucket' => [
        'enabled' => true,
        'initial_tokens' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota Configuration
    |--------------------------------------------------------------------------
    */
    'quotas' => [
        'enabled' => true,
        'reset_day' => 1,
        'reset_timezone' => 'UTC',
        'grace_period' => 0,
        'grace_percentage' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Configuration
    |--------------------------------------------------------------------------
    */
    'response' => [
        'status_code' => 429,
        'detailed_errors' => env('APP_DEBUG', false),
        'transformer' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers Configuration
    |--------------------------------------------------------------------------
    */
    'headers' => [
        'enabled' => true,
        'rate_limit' => [
            'limit' => 'X-RateLimit-Limit',
            'remaining' => 'X-RateLimit-Remaining',
            'reset' => 'X-RateLimit-Reset',
            'policy' => 'X-RateLimit-Policy',
        ],
        'quota' => [
            'enabled' => true,
            'limit' => 'X-Quota-Limit',
            'remaining' => 'X-Quota-Remaining',
            'reset' => 'X-Quota-Reset',
        ],
        'retry_after' => [
            'enabled' => true,
            'format' => 'seconds',
        ],
        'plan' => [
            'enabled' => true,
            'header' => 'X-RateLimit-Plan',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bypass Configuration
    |--------------------------------------------------------------------------
    */
    'bypass' => [
        'ips' => [
            '127.0.0.1',
        ],
        'api_keys' => [],
        'users' => [
            'ids' => [],
            'attribute' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts & Notifications
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'enabled' => env('THROTTLE_SMART_ALERTS', false),
        'thresholds' => [
            'warning' => 80,
            'critical' => 95,
        ],
        'channels' => ['slack', 'mail'],
        'cooldown' => 15,
        'notify' => [
            'user' => true,
            'admin' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics & Logging
    |--------------------------------------------------------------------------
    */
    'analytics' => [
        'enabled' => env('THROTTLE_SMART_ANALYTICS', false),
        'driver' => 'database',
        'track' => [
            'requests' => true,
            'limited' => true,
            'quota_usage' => true,
            'bandwidth' => false,
        ],
        'retention' => 90,
        'aggregation' => 'hourly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'plans' => [
            'enabled' => true,
            'ttl' => 300,
        ],
    ],
];

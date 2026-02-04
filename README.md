# Laravel API Throttle Smart

> Intelligent, plan-aware rate limiting for Laravel APIs - Multi-algorithm, multi-tenant, quota management

[![Latest Version on Packagist](https://img.shields.io/packagist/v/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)
[![Tests](https://img.shields.io/github/actions/workflow/status/grazulex/laravel-api-throttle-smart/tests.yml?label=tests)](https://github.com/grazulex/laravel-api-throttle-smart/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)
[![License](https://img.shields.io/packagist/l/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)

---

## Features

- **Plan-Based Limits** - Define different rate limits per subscription plan (Free, Pro, Enterprise)
- **Multiple Algorithms** - Fixed Window, Sliding Window, or Token Bucket
- **Multi-Window Limits** - Per-second, minute, hour, day, and month limits
- **Quota Management** - Daily/monthly API quota with alerts and top-ups
- **Multi-Tenant Scoping** - Scope by user, team, tenant, or IP
- **Multiple Storage Drivers** - Cache, Redis, or Database
- **RFC 7231 Compliant** - Standard rate limit headers
- **Burst Handling** - Token bucket algorithm for controlled bursts
- **Artisan Commands** - Status, analytics, cleanup, and management tools
- **Testing Helpers** - Fluent testing API with `ThrottleSmartFake`

---

## Requirements

- PHP 8.3+
- Laravel 11.x or 12.x

---

## Installation

```bash
composer require grazulex/laravel-api-throttle-smart
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="throttle-smart-config"
```

For database driver, publish migrations:

```bash
php artisan vendor:publish --tag="throttle-smart-migrations"
php artisan migrate
```

---

## Quick Start

Apply the middleware to routes:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'throttle.smart'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
});
```

The middleware automatically:
- Resolves the user's plan from the `plan` attribute
- Applies plan-specific rate limits
- Adds standard rate limit headers
- Returns 429 when limits exceeded

### Response Headers

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1706835600
X-RateLimit-Policy: 60;w=60
X-RateLimit-Plan: pro
X-Quota-Limit: 1000000
X-Quota-Remaining: 999542
X-Quota-Reset: 1709251200
```

When rate limited:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 45
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1706835600
```

---

## Plan Configuration

```php
// config/throttle-smart.php
'plans' => [
    'free' => [
        'label' => 'Free Plan',
        'requests_per_minute' => 60,
        'requests_per_hour' => 500,
        'requests_per_day' => 5000,
        'requests_per_month' => 100000,
        'burst_size' => 10,
        'burst_refill_rate' => 1,
    ],

    'pro' => [
        'label' => 'Pro Plan',
        'requests_per_minute' => 300,
        'requests_per_hour' => 5000,
        'requests_per_day' => 50000,
        'requests_per_month' => 1000000,
        'burst_size' => 50,
        'burst_refill_rate' => 5,
    ],

    'enterprise' => [
        'label' => 'Enterprise Plan',
        'requests_per_minute' => 1000,
        'requests_per_hour' => 20000,
        'requests_per_day' => 200000,
        'requests_per_month' => null, // Unlimited
        'burst_size' => 200,
        'burst_refill_rate' => 20,
    ],
],
```

---

## PHP Attributes

```php
use Grazulex\ThrottleSmart\Attributes\RateLimit;
use Grazulex\ThrottleSmart\Attributes\QuotaCost;

class ApiController extends Controller
{
    #[RateLimit(perMinute: 10, perHour: 100)]
    public function sensitiveEndpoint(Request $request)
    {
        // Custom limits for this endpoint
    }

    #[QuotaCost(5)]
    public function expensiveOperation(Request $request)
    {
        // Costs 5 quota units instead of 1
    }
}
```

---

## Programmatic Usage

```php
use Grazulex\ThrottleSmart\Facades\ThrottleSmart;

// Get rate limits for a user
$limits = ThrottleSmart::getLimits($user);
$limits->minute['remaining']; // 58
$limits->isLimited; // false

// Get quota information
$quota = ThrottleSmart::getQuota($user);
$quota->monthly['remaining']; // 999542
$quota->percentageUsed; // 0.05

// Check without consuming
if (ThrottleSmart::wouldLimit($request)) {
    return response()->json(['message' => 'Please slow down'], 429);
}

// Manually consume quota
ThrottleSmart::consume(5); // Consume 5 units

// Reset limits for a user
ThrottleSmart::reset("user:{$user->id}");

// Grant bonus quota
ThrottleSmart::addQuota($user, 10000, 'Customer support bonus');
```

---

## Rate Limiting Algorithms

### Fixed Window (Default)
Simple counter-based limiting with discrete time windows.

### Sliding Window
Weighted average across windows for smoother rate limiting.

```php
'sliding_window' => [
    'enabled' => true,
    'precision' => 1,
],
```

### Token Bucket
Allows controlled bursts while maintaining average rate.

```php
'token_bucket' => [
    'enabled' => true,
    'initial_tokens' => null, // Start with full bucket
],
```

---

## Artisan Commands

```bash
# View rate limit status
php artisan throttle:status

# Check specific user
php artisan throttle:user --user=123

# View analytics
php artisan throttle:analytics --period=day

# Reset user limits
php artisan throttle:reset --user=123

# Reset user quota
php artisan throttle:reset-quota --user=123

# Grant bonus quota
php artisan throttle:grant-quota --user=123 --amount=10000 --reason="Support"

# Cleanup old data
php artisan throttle:cleanup --days=90
```

---

## Events

```php
use Grazulex\ThrottleSmart\Events\RateLimitExceeded;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Events\QuotaExceeded;
use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;

// In EventServiceProvider
protected $listen = [
    RateLimitExceeded::class => [
        SendRateLimitNotification::class,
    ],
    QuotaThresholdReached::class => [
        SendQuotaWarningEmail::class,
    ],
];
```

---

## Testing

```php
use Grazulex\ThrottleSmart\Facades\ThrottleSmart;

public function test_rate_limiting(): void
{
    ThrottleSmart::fake();

    // Make requests...

    ThrottleSmart::assertLimitExceeded('user:123');
    ThrottleSmart::assertNotLimited('user:456');
    ThrottleSmart::assertQuotaConsumed(150);
}
```

Integration testing:

```php
public function test_api_is_rate_limited(): void
{
    $user = User::factory()->create(['plan' => 'free']);

    // Make 61 requests (free plan allows 60/min)
    for ($i = 0; $i < 61; $i++) {
        $response = $this->actingAs($user)
            ->getJson('/api/users');
    }

    $response->assertStatus(429)
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
}
```

---

## Quality Tools

```bash
# Run tests
composer test

# Code style (Laravel Pint)
composer pint

# Static analysis (PHPStan Level 5)
composer analyse
```

---

## Error Responses

| Code | Status | Description |
|------|--------|-------------|
| `RATE_LIMIT_EXCEEDED` | 429 | Rate limit exceeded for time window |
| `QUOTA_EXCEEDED` | 429 | Monthly/daily quota exceeded |

---

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email security@grazulex.dev instead of using the issue tracker.

## Credits

- [Jean-Marc Strauven](https://github.com/Grazulex)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

## See Also

- [laravel-api-idempotency](https://github.com/Grazulex/laravel-api-idempotency) - API idempotency support
- [laravel-apiroute](https://github.com/Grazulex/laravel-apiroute) - API versioning lifecycle management

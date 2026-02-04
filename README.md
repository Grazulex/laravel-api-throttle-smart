# Laravel API Throttle Smart

[![Latest Version on Packagist](https://img.shields.io/packagist/v/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)
[![Tests](https://img.shields.io/github/actions/workflow/status/grazulex/laravel-api-throttle-smart/tests.yml?label=tests)](https://github.com/grazulex/laravel-api-throttle-smart/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)
[![License](https://img.shields.io/packagist/l/grazulex/laravel-api-throttle-smart.svg?style=flat-square)](https://packagist.org/packages/grazulex/laravel-api-throttle-smart)

> Intelligent, plan-aware rate limiting for Laravel APIs - Built for SaaS, multi-tenant, and enterprise applications

## Features

- **Plan-Based Limits** - Different quotas for Free, Pro, Enterprise tiers
- **Multi-Tenant Ready** - Per-tenant, per-team, per-user scoping
- **Quota Management** - Daily, monthly, and rolling window quotas
- **Burst Handling** - Allow temporary bursts while maintaining limits
- **RFC 7231 Compliant** - Standard rate limit headers
- **Real-Time Analytics** - Dashboard for monitoring usage patterns
- **Redis Optimized** - Lua scripts for atomic operations
- **Testing Helpers** - Fluent API for testing rate limits

## Requirements

- PHP 8.3+
- Laravel 11.x or 12.x
- Redis (recommended) or any Laravel cache driver

## Installation

```bash
composer require grazulex/laravel-api-throttle-smart
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="throttle-smart-config"
```

For database-backed quotas and analytics:

```bash
php artisan vendor:publish --tag="throttle-smart-migrations"
php artisan migrate
```

## Quick Start

### Apply to Routes

```php
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle.smart'])->group(function () {
    Route::apiResource('users', UserController::class);
});
```

### Response Headers

```http
HTTP/1.1 200 OK
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 247
X-RateLimit-Reset: 1705312800
X-RateLimit-Plan: pro
X-Quota-Limit: 1000000
X-Quota-Remaining: 543211
```

## Documentation

See the full documentation in the [Wiki](https://github.com/grazulex/laravel-api-throttle-smart/wiki).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

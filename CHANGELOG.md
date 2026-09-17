# Changelog

All notable changes to this project will be documented in this file.

## [0.2.0](https://github.com/Grazulex/laravel-api-throttle-smart/releases/tag/v0.2.0) (2026-09-17)

### Added

- Laravel 13 support (`illuminate/*` `^12.0|^13.0`)

### Changed

- PHP 8.3 remains the minimum supported version; CI now also runs on PHP 8.4
- Development dependencies updated: Orchestra Testbench `^10.0|^11.0`, Pest `^3.8|^4.0`, Pest Laravel plugin `^3.2|^4.0`, Larastan `^3.4`, PHPStan `^2.1`
- CI test matrix now covers PHP 8.3/8.4, Laravel 12/13 and prefer-lowest/prefer-stable dependency sets; dedicated code-style and static-analysis workflows added

### Removed

- Laravel 11 support (end of life)

### Fixed

- Code style adjustments in `CacheDriver` and `RedisDriver` (fully qualified return types replaced by imports) to satisfy the current Laravel Pint preset

## [0.1.0](https://github.com/Grazulex/laravel-api-throttle-smart/releases/tag/v0.1.0) (2026-02-04)

### Features

- add rate limiting algorithms (FixedWindow, SlidingWindow, TokenBucket) ([032093f](https://github.com/Grazulex/laravel-api-throttle-smart/commit/032093fdb23c5beebf80e5bd4f5e6a5ff1058933))
- initial package structure ([d1a3a70](https://github.com/Grazulex/laravel-api-throttle-smart/commit/d1a3a707f7c7ff7061ccf1b0b61f14121361c99e))

### Bug Fixes

- make SlidingWindowLimiter test time-independent ([292c913](https://github.com/Grazulex/laravel-api-throttle-smart/commit/292c9138ebef20b93bf527d42ce9d62f924e642e))
- add Feature tests directory and update README ([a61a244](https://github.com/Grazulex/laravel-api-throttle-smart/commit/a61a244dc93113b5d66ccec4aec7b46ca2c1be80))

### Tests

- add comprehensive unit tests for full coverage ([ac7455b](https://github.com/Grazulex/laravel-api-throttle-smart/commit/ac7455b0cd94d40166f523e8321766e316b26128))

### Styles

- fix pint code style (new stdClass without parentheses) ([a0cddfe](https://github.com/Grazulex/laravel-api-throttle-smart/commit/a0cddfe3e35ef3727fbca2270fe18fe03fd32699))

<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\ConcurrencyStoreInterface;
use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Drivers\CacheDriver;
use Grazulex\ThrottleSmart\Drivers\DatabaseDriver;
use Grazulex\ThrottleSmart\Events\RateLimitExceeded;
use Grazulex\ThrottleSmart\Http\Middleware\ThrottleSmartMiddleware;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->cacheDriver = new CacheDriver(app('cache'), ['prefix' => 'test:', 'store' => 'array']);

    $this->config = fn (?int $concurrent, array $extra = []): array => array_merge([
        'default_plan' => 'free',
        'plans' => [
            'free' => [
                'requests_per_minute' => 1000,
                'concurrent_requests' => $concurrent,
            ],
        ],
        'driver' => 'cache',
        'drivers' => ['cache' => ['prefix' => 'throttle:']],
        'bypass' => ['ips' => []],
    ], $extra);

    $this->makeRequest = function (): Request {
        $request = Request::create('/api/users', 'GET', server: ['REMOTE_ADDR' => '10.0.0.2']);
        app()->instance('request', $request);

        return $request;
    };
});

describe('cache driver', function (): void {
    it('acquires slots up to the limit', function (): void {
        $first = $this->cacheDriver->acquireConcurrencySlot('key', 2, 60);
        $second = $this->cacheDriver->acquireConcurrencySlot('key', 2, 60);
        $third = $this->cacheDriver->acquireConcurrencySlot('key', 2, 60);

        expect($first)->toBeString()
            ->and($second)->toBeString()
            ->and($first)->not->toBe($second)
            ->and($third)->toBeNull()
            ->and($this->cacheDriver->getConcurrentCount('key'))->toBe(2);
    });

    it('frees a slot on release', function (): void {
        $token = $this->cacheDriver->acquireConcurrencySlot('key', 1, 60);
        expect($this->cacheDriver->acquireConcurrencySlot('key', 1, 60))->toBeNull();

        $this->cacheDriver->releaseConcurrencySlot('key', $token);

        expect($this->cacheDriver->getConcurrentCount('key'))->toBe(0)
            ->and($this->cacheDriver->acquireConcurrencySlot('key', 1, 60))->toBeString();
    });

    it('reclaims slots that were never released after the ttl', function (): void {
        $this->cacheDriver->acquireConcurrencySlot('key', 1, 10);
        expect($this->cacheDriver->acquireConcurrencySlot('key', 1, 10))->toBeNull();

        $this->travel(11)->seconds();

        expect($this->cacheDriver->getConcurrentCount('key'))->toBe(0)
            ->and($this->cacheDriver->acquireConcurrencySlot('key', 1, 10))->toBeString();
    });

    it('keeps keys independent', function (): void {
        $this->cacheDriver->acquireConcurrencySlot('a', 1, 60);

        expect($this->cacheDriver->acquireConcurrencySlot('b', 1, 60))->toBeString();
    });
});

describe('database driver', function (): void {
    beforeEach(function (): void {
        (include __DIR__.'/../../database/migrations/2024_01_01_000003_create_rate_limits_table.php')->up();

        $this->dbDriver = new DatabaseDriver(app('db'), ['connection' => 'testing']);
    });

    it('acquires slots up to the limit and frees them on release', function (): void {
        $first = $this->dbDriver->acquireConcurrencySlot('throttle:user:1:concurrent', 2, 60);
        $second = $this->dbDriver->acquireConcurrencySlot('throttle:user:1:concurrent', 2, 60);

        expect($first)->toBeString()
            ->and($second)->toBeString()
            ->and($this->dbDriver->acquireConcurrencySlot('throttle:user:1:concurrent', 2, 60))->toBeNull()
            ->and($this->dbDriver->getConcurrentCount('throttle:user:1:concurrent'))->toBe(2);

        $this->dbDriver->releaseConcurrencySlot('throttle:user:1:concurrent', $first);

        expect($this->dbDriver->getConcurrentCount('throttle:user:1:concurrent'))->toBe(1)
            ->and($this->dbDriver->acquireConcurrencySlot('throttle:user:1:concurrent', 2, 60))->toBeString();
    });

    it('reclaims slots that were never released after the ttl', function (): void {
        $this->dbDriver->acquireConcurrencySlot('key', 1, 10);
        expect($this->dbDriver->acquireConcurrencySlot('key', 1, 10))->toBeNull();

        $this->travel(11)->seconds();

        expect($this->dbDriver->acquireConcurrencySlot('key', 1, 10))->toBeString()
            ->and($this->dbDriver->getConcurrentCount('key'))->toBe(1);
    });

    it('treats like wildcards in keys literally', function (): void {
        $this->dbDriver->acquireConcurrencySlot('userX1', 1, 60);
        $this->dbDriver->acquireConcurrencySlot('userXY', 1, 60);

        expect($this->dbDriver->getConcurrentCount('user_1'))->toBe(0)
            ->and($this->dbDriver->getConcurrentCount('user%'))->toBe(0)
            ->and($this->dbDriver->acquireConcurrencySlot('user_1', 1, 60))->toBeString()
            ->and($this->dbDriver->acquireConcurrencySlot('user%', 1, 60))->toBeString()
            ->and($this->dbDriver->getConcurrentCount('user_1'))->toBe(1);
    });
});

describe('manager', function (): void {
    it('does not reserve a slot when the plan has no concurrent limit', function (): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(null));

        expect($manager->acquireConcurrencySlot())
            ->toBe(['allowed' => true, 'limit' => null, 'key' => null, 'token' => null]);
    });

    it('allows the request when the driver cannot track concurrency', function (): void {
        ($this->makeRequest)();
        $driver = Mockery::mock(StorageDriverInterface::class);
        $manager = new ThrottleSmartManager($driver, ($this->config)(1));

        expect($manager->acquireConcurrencySlot()['allowed'])->toBeTrue()
            ->and($manager->getConcurrentRequests())->toBe(0);
    });

    it('allows the request when the storage fails', function (): void {
        ($this->makeRequest)();
        $driver = Mockery::mock(StorageDriverInterface::class.', '.ConcurrencyStoreInterface::class);
        $driver->shouldReceive('acquireConcurrencySlot')->andThrow(new RuntimeException('Storage down'));
        $manager = new ThrottleSmartManager($driver, ($this->config)(1));

        expect($manager->acquireConcurrencySlot())
            ->toBe(['allowed' => true, 'limit' => 1, 'key' => null, 'token' => null]);
    });

    it('passes the configured safety ttl to the driver', function (): void {
        ($this->makeRequest)();
        $driver = Mockery::mock(StorageDriverInterface::class.', '.ConcurrencyStoreInterface::class);
        $driver->shouldReceive('acquireConcurrencySlot')
            ->with('throttle:ip:10.0.0.2:concurrent', 3, 120)
            ->once()
            ->andReturn('token');
        $manager = new ThrottleSmartManager($driver, ($this->config)(3, ['concurrency' => ['ttl' => 120]]));

        expect($manager->acquireConcurrencySlot())->toBe([
            'allowed' => true,
            'limit' => 3,
            'key' => 'throttle:ip:10.0.0.2:concurrent',
            'token' => 'token',
        ]);
    });

    it('defaults the safety ttl to 60 seconds', function (): void {
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));

        expect($manager->getConcurrencyTtl())->toBe(60);
    });
});

describe('middleware', function (): void {
    beforeEach(function (): void {
        $this->middleware = new ThrottleSmartMiddleware(
            new ThrottleSmartManager($this->cacheDriver, ($this->config)(2))
        );
        $this->next = fn (): Response => new Response('ok');
    });

    it('rejects requests over the concurrent limit until a slot is released', function (): void {
        Event::fake([RateLimitExceeded::class]);

        $first = ($this->makeRequest)();
        expect($this->middleware->handle($first, $this->next)->getStatusCode())->toBe(200);

        $second = ($this->makeRequest)();
        expect($this->middleware->handle($second, $this->next)->getStatusCode())->toBe(200);

        $third = ($this->makeRequest)();
        $response = $this->middleware->handle($third, $this->next);

        expect($response->getStatusCode())->toBe(429)
            ->and($response->headers->get('Retry-After'))->toBe('1')
            ->and(json_decode((string) $response->getContent(), true)['error'])->toMatchArray([
                'code' => 'CONCURRENT_LIMIT_EXCEEDED',
                'limit_type' => 'concurrent',
                'limit' => 2,
            ]);

        Event::assertDispatched(
            RateLimitExceeded::class,
            fn (RateLimitExceeded $event): bool => $event->limitType === 'concurrent' && $event->limit === 2
        );

        $this->middleware->terminate($first, new Response('ok'));

        $fourth = ($this->makeRequest)();
        expect($this->middleware->handle($fourth, $this->next)->getStatusCode())->toBe(200);
    });

    it('releases the slot only once', function (): void {
        $request = ($this->makeRequest)();
        $this->middleware->handle($request, $this->next);

        $this->middleware->terminate($request, new Response('ok'));
        $this->middleware->terminate($request, new Response('ok'));

        expect($this->cacheDriver->getConcurrentCount('throttle:ip:10.0.0.2:concurrent'))->toBe(0)
            ->and($request->attributes->has(ThrottleSmartMiddleware::CONCURRENCY_ATTRIBUTE))->toBeFalse();
    });

    it('releases the slot when the request throws', function (): void {
        $request = ($this->makeRequest)();

        expect(fn () => $this->middleware->handle($request, function (): never {
            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class, 'boom');

        expect($this->cacheDriver->getConcurrentCount('throttle:ip:10.0.0.2:concurrent'))->toBe(0);
    });

    it('reserves a single slot when the middleware runs twice on a request', function (): void {
        $request = ($this->makeRequest)();

        $this->middleware->handle($request, fn (Request $request): Response => $this->middleware->handle($request, $this->next));

        expect($this->cacheDriver->getConcurrentCount('throttle:ip:10.0.0.2:concurrent'))->toBe(1);
    });

    it('does not reserve a slot for rejected requests', function (): void {
        $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->cacheDriver, ($this->config)(2, [
            'plans' => ['free' => ['requests_per_minute' => 1, 'concurrent_requests' => 2]],
        ])));

        $middleware->handle(($this->makeRequest)(), $this->next);
        $response = $middleware->handle(($this->makeRequest)(), $this->next);

        expect($response->getStatusCode())->toBe(429)
            ->and($this->cacheDriver->getConcurrentCount('throttle:ip:10.0.0.2:concurrent'))->toBe(1);
    });
});

it('holds the slot during the request and releases it after the response', function (): void {
    config()->set('throttle-smart.bypass.ips', []);
    config()->set('throttle-smart.drivers.cache.store', 'array');
    config()->set('throttle-smart.plans', ['free' => ['concurrent_requests' => 1]]);

    Route::get('/concurrency-check', fn (): string => (string) app(ThrottleSmartManager::class)->getConcurrentRequests())
        ->middleware('throttle.smart');

    // With a limit of 1, each request only passes if the previous one released its slot
    $this->get('/concurrency-check')->assertOk()->assertContent('1');
    $this->get('/concurrency-check')->assertOk()->assertContent('1');
    $this->get('/concurrency-check')->assertOk()->assertContent('1');
});

<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Grazulex\ThrottleSmart\Drivers\CacheDriver;
use Grazulex\ThrottleSmart\Events\QuotaExceeded;
use Grazulex\ThrottleSmart\Http\Middleware\ThrottleSmartMiddleware;
use Grazulex\ThrottleSmart\Testing\ThrottleSmartFake;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    $this->freezeTime();

    $this->cacheDriver = new CacheDriver(app('cache'), ['prefix' => 'test:', 'store' => 'array']);

    $this->config = fn (?int $bandwidthMb, array $extra = []): array => array_merge([
        'default_plan' => 'free',
        'plans' => [
            'free' => [
                'requests_per_minute' => 1000,
                'bandwidth_per_day_mb' => $bandwidthMb,
            ],
        ],
        'driver' => 'cache',
        'drivers' => ['cache' => ['prefix' => 'throttle:']],
        'bypass' => ['ips' => []],
    ], $extra);

    $this->makeRequest = function (string $ip = '10.0.0.3'): Request {
        $request = Request::create('/api/files', 'GET', server: ['REMOTE_ADDR' => $ip]);
        app()->instance('request', $request);

        return $request;
    };

    $this->key = fn (string $scope = 'ip:10.0.0.3'): string => 'throttle:bandwidth:'.$scope.':'.now()->format('Y-m-d');
});

describe('manager', function (): void {
    it('reports no limit when the plan has no bandwidth limit', function (?int $bandwidthMb): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)($bandwidthMb));

        expect($manager->getBandwidth())->toMatchArray([
            'limit' => null,
            'used' => 0,
            'remaining' => null,
            'exceeded' => false,
            'key' => null,
        ]);
    })->with([null, 0]);

    it('reports the daily usage in bytes', function (): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(2));

        $manager->consumeBandwidth(($this->key)(), 1000);
        $bandwidth = $manager->getBandwidth();

        expect($bandwidth)->toMatchArray([
            'limit' => 2 * 1_048_576,
            'used' => 1000,
            'remaining' => 2 * 1_048_576 - 1000,
            'exceeded' => false,
            'key' => ($this->key)(),
        ])
            ->and($bandwidth['resets_at']->equalTo(now()->addDay()->startOfDay()))->toBeTrue();
    });

    it('is exceeded once the usage reaches the limit', function (): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));

        $manager->consumeBandwidth(($this->key)(), 1_048_576);

        expect($manager->getBandwidth())->toMatchArray([
            'remaining' => 0,
            'exceeded' => true,
        ]);
    });

    it('ignores empty sizes', function (): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));

        $manager->consumeBandwidth(($this->key)(), 0);
        $manager->consumeBandwidth(($this->key)(), -10);

        expect($manager->getBandwidth()['used'])->toBe(0);
    });

    it('uses the user as the counter scope', function (): void {
        ($this->makeRequest)();
        $user = new stdClass;
        $user->id = 9;
        $user->plan = 'free';
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));

        expect($manager->getBandwidth($user)['key'])->toBe(($this->key)('user:9'));
    });

    it('starts a new counter every day', function (): void {
        ($this->makeRequest)();
        $manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));
        $manager->consumeBandwidth(($this->key)(), 1_048_576);

        $this->travelTo(now()->addDay()->startOfDay());

        expect($manager->getBandwidth())->toMatchArray([
            'used' => 0,
            'exceeded' => false,
            'key' => ($this->key)(),
        ]);
    });

    it('allows the request when the storage fails', function (): void {
        ($this->makeRequest)();
        $driver = Mockery::mock(StorageDriverInterface::class);
        $driver->shouldReceive('getQuota')->andThrow(new RuntimeException('Storage down'));
        $driver->shouldReceive('incrementQuota')->andThrow(new RuntimeException('Storage down'));
        $manager = new ThrottleSmartManager($driver, ($this->config)(1));

        $manager->consumeBandwidth(($this->key)(), 10);

        expect($manager->getBandwidth())->toMatchArray([
            'used' => 0,
            'exceeded' => false,
        ]);
    });

    it('is not tracked by the fake', function (): void {
        $fake = new ThrottleSmartFake;
        $fake->consumeBandwidth('key', 10);

        expect($fake->getBandwidth())->toMatchArray([
            'limit' => null,
            'exceeded' => false,
            'key' => null,
        ]);
    });
});

describe('middleware', function (): void {
    beforeEach(function (): void {
        $this->manager = new ThrottleSmartManager($this->cacheDriver, ($this->config)(1));
        $this->middleware = new ThrottleSmartMiddleware($this->manager);
        $this->used = fn (): int => $this->cacheDriver->getQuota(($this->key)());
    });

    it('counts the response size once the response has been sent', function (): void {
        $request = ($this->makeRequest)();
        $response = $this->middleware->handle($request, fn (): Response => new Response(str_repeat('a', 1500)));

        expect($response->getStatusCode())->toBe(200)
            ->and(($this->used)())->toBe(0);

        $this->middleware->terminate($request, $response);

        expect(($this->used)())->toBe(1500)
            ->and($request->attributes->has(ThrottleSmartMiddleware::BANDWIDTH_ATTRIBUTE))->toBeFalse();
    });

    it('counts a response only once', function (): void {
        $request = ($this->makeRequest)();
        $next = fn (): Response => new Response('hello');
        $response = $this->middleware->handle($request, fn (Request $request): Response => $this->middleware->handle($request, $next));

        $this->middleware->terminate($request, $response);
        $this->middleware->terminate($request, $response);

        expect(($this->used)())->toBe(5);
    });

    it('rejects requests once the daily bandwidth is used up', function (): void {
        Event::fake([QuotaExceeded::class]);
        $this->manager->consumeBandwidth(($this->key)(), 1_048_576);

        $request = ($this->makeRequest)();
        $response = $this->middleware->handle($request, fn (): Response => new Response('ok'));
        $this->middleware->terminate($request, $response);

        $error = json_decode((string) $response->getContent(), true)['error'];
        $retryAfter = max(1, (int) ceil(now()->diffInSeconds(now()->addDay()->startOfDay(), true)));

        expect($response->getStatusCode())->toBe(429)
            ->and($response->headers->get('Retry-After'))->toBe((string) $retryAfter)
            ->and($error)->toMatchArray([
                'code' => 'BANDWIDTH_EXCEEDED',
                'type' => 'quota',
                'quota_type' => 'bandwidth',
                'limit' => 1_048_576,
                'used' => 1_048_576,
                'retry_after' => $retryAfter,
            ])
            ->and(($this->used)())->toBe(1_048_576);

        // No authenticated user: no event
        Event::assertNotDispatched(QuotaExceeded::class);
    });

    it('fires a QuotaExceeded event for an authenticated user', function (): void {
        Event::fake([QuotaExceeded::class]);

        $user = new User;
        $user->id = 5;
        $user->plan = 'free';
        $this->manager->consumeBandwidth(($this->key)('user:5'), 2_000_000);

        $request = Request::create('/api/files', 'GET', server: ['REMOTE_ADDR' => '10.0.0.3']);
        app()->instance('request', $request);
        $request->setUserResolver(fn (): User => $user);

        $response = $this->middleware->handle($request, fn (): Response => new Response('ok'));

        expect($response->getStatusCode())->toBe(429);

        Event::assertDispatched(
            QuotaExceeded::class,
            fn (QuotaExceeded $event): bool => $event->quotaType === 'bandwidth'
                && $event->limit === 1_048_576
                && $event->used === 2_000_000
                && $event->user === $user
        );
    });

    it('allows the request again the next day', function (): void {
        $this->manager->consumeBandwidth(($this->key)(), 1_048_576);
        expect($this->middleware->handle(($this->makeRequest)(), fn (): Response => new Response('ok'))->getStatusCode())->toBe(429);

        $this->travelTo(now()->addDay()->startOfDay());

        expect($this->middleware->handle(($this->makeRequest)(), fn (): Response => new Response('ok'))->getStatusCode())->toBe(200);
    });

    it('does not count requests rejected by a rate limit', function (): void {
        $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->cacheDriver, ($this->config)(1, [
            'plans' => ['free' => ['requests_per_minute' => 1, 'bandwidth_per_day_mb' => 1]],
        ])));

        $first = ($this->makeRequest)();
        $middleware->terminate($first, $middleware->handle($first, fn (): Response => new Response('ok')));

        $second = ($this->makeRequest)();
        $response = $middleware->handle($second, fn (): Response => new Response('ok'));
        $middleware->terminate($second, $response);

        expect($response->getStatusCode())->toBe(429)
            ->and(($this->used)())->toBe(2);
    });

    it('does not count bypassed requests', function (): void {
        $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->cacheDriver, ($this->config)(1, [
            'bypass' => ['ips' => ['10.0.0.3']],
        ])));

        $request = ($this->makeRequest)();
        $middleware->terminate($request, $middleware->handle($request, fn (): Response => new Response('ok')));

        expect(($this->used)())->toBe(0);
    });

    it('does not count anything when the plan has no bandwidth limit', function (): void {
        $middleware = new ThrottleSmartMiddleware(new ThrottleSmartManager($this->cacheDriver, ($this->config)(null)));

        $request = ($this->makeRequest)();
        $middleware->terminate($request, $middleware->handle($request, fn (): Response => new Response('ok')));

        expect(($this->used)())->toBe(0)
            ->and($request->attributes->has(ThrottleSmartMiddleware::BANDWIDTH_ATTRIBUTE))->toBeFalse();
    });

    it('prefers the Content-Length header', function (): void {
        $request = ($this->makeRequest)();
        $response = $this->middleware->handle($request, fn (): Response => new Response('ok', 200, ['Content-Length' => '4096']));

        $this->middleware->terminate($request, $response);

        expect(($this->used)())->toBe(4096);
    });

    it('uses the file size of file responses', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'bandwidth');
        file_put_contents($path, str_repeat('b', 321));

        try {
            $request = ($this->makeRequest)();
            $response = $this->middleware->handle($request, fn (): BinaryFileResponse => new BinaryFileResponse($path));

            $this->middleware->terminate($request, $response);

            expect(($this->used)())->toBe(321);
        } finally {
            @unlink($path);
        }
    });

    it('counts streamed responses without Content-Length as empty', function (): void {
        $request = ($this->makeRequest)();
        $response = $this->middleware->handle($request, fn (): StreamedResponse => new StreamedResponse(function (): void {
            echo 'streamed';
        }));

        $this->middleware->terminate($request, $response);

        expect(($this->used)())->toBe(0);
    });
});

it('counts the response size through the http kernel', function (): void {
    config()->set('throttle-smart.bypass.ips', []);
    config()->set('throttle-smart.drivers.cache.store', 'array');
    config()->set('throttle-smart.plans', ['free' => ['bandwidth_per_day_mb' => 1]]);

    Route::get('/bandwidth-check', fn (): string => (string) app(ThrottleSmartManager::class)->getBandwidth()['used'])
        ->middleware('throttle.smart');

    $this->get('/bandwidth-check')->assertOk()->assertContent('0');
    $this->get('/bandwidth-check')->assertOk()->assertContent('1');
    $this->get('/bandwidth-check')->assertOk()->assertContent('2');
});

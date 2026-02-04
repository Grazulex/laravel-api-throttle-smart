<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Facades;

use Carbon\Carbon;
use Closure;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Grazulex\ThrottleSmart\Testing\ThrottleSmartFake;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RateLimits getLimits(mixed $user = null)
 * @method static int getRemaining(mixed $user, string $type = 'minute')
 * @method static QuotaInfo getQuota(mixed $user = null)
 * @method static void consume(int $cost = 1)
 * @method static void reset(string $key)
 * @method static void resetQuota(mixed $user)
 * @method static void addQuota(mixed $user, int $amount, ?string $reason = null)
 * @method static void setTemporaryLimit(mixed $user, string $type, int $limit, Carbon $until)
 * @method static bool wouldLimit(Request $request)
 * @method static bool isBypassed(Request $request)
 * @method static self resolvePlanUsing(Closure $callback)
 * @method static self resolveKeyUsing(Closure $callback)
 * @method static self forUser(mixed $user)
 *
 * @see ThrottleSmartManager
 */
class ThrottleSmart extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): ThrottleSmartFake
    {
        static::swap($fake = new ThrottleSmartFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ThrottleSmartManager::class;
    }
}

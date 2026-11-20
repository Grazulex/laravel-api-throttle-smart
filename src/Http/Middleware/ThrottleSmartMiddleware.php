<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Grazulex\ThrottleSmart\Events\QuotaExceeded;
use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Events\RateLimitExceeded;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ThrottleSmartMiddleware
{
    /**
     * Request attribute holding the concurrency slot reserved for the request.
     */
    public const string CONCURRENCY_ATTRIBUTE = 'throttle-smart.concurrency';

    /**
     * Request attribute holding the daily bandwidth counter key of the request.
     */
    public const string BANDWIDTH_ATTRIBUTE = 'throttle-smart.bandwidth';

    public function __construct(
        protected ThrottleSmartManager $manager,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|JsonResponse)  $next
     */
    public function handle(Request $request, Closure $next, ?string $maxAttempts = null, ?string $decayMinutes = null): SymfonyResponse
    {
        // Check if throttling is enabled
        if (! config('throttle-smart.enabled', true)) {
            return $next($request);
        }

        // Check for bypass
        if ($this->manager->isBypassed($request)) {
            return $next($request);
        }

        // Get rate limits
        $limits = $this->manager->getLimits($request->user());

        // Check if rate limited
        if ($limits->isLimited) {
            $this->manager->recordAnalytics($request, $limits->plan, 'rate_limited');

            return $this->buildRateLimitedResponse($request, $limits);
        }

        // Check quota
        $quota = $this->manager->getQuota($request->user());
        if ($quota->isExceeded) {
            $this->manager->recordAnalytics($request, $limits->plan, 'quota_exceeded');

            return $this->buildQuotaExceededResponse($request, $quota);
        }

        // Check the daily bandwidth quota (the response size is counted in terminate())
        $bandwidth = $this->manager->getBandwidth($request->user());
        if ($bandwidth['exceeded']) {
            $this->manager->recordAnalytics($request, $limits->plan, 'quota_exceeded');

            return $this->buildBandwidthExceededResponse($request, $bandwidth);
        }

        // Reserve a concurrency slot (released in terminate())
        if (! $request->attributes->has(self::CONCURRENCY_ATTRIBUTE)) {
            $concurrency = $this->manager->acquireConcurrencySlot($request->user());

            if (! $concurrency['allowed']) {
                $this->manager->recordAnalytics($request, $limits->plan, 'rate_limited');

                return $this->buildConcurrencyLimitedResponse($request, $limits, (int) $concurrency['limit']);
            }

            if ($concurrency['key'] !== null && $concurrency['token'] !== null) {
                $request->attributes->set(self::CONCURRENCY_ATTRIBUTE, [
                    'key' => $concurrency['key'],
                    'token' => $concurrency['token'],
                ]);
            }
        }

        // Remember the bandwidth counter so that terminate() adds the response size once
        if ($bandwidth['key'] !== null && ! $request->attributes->has(self::BANDWIDTH_ATTRIBUTE)) {
            $request->attributes->set(self::BANDWIDTH_ATTRIBUTE, $bandwidth['key']);
        }

        // Record the allowed request
        $this->manager->recordAnalytics($request, $limits->plan, 'allowed');

        // Check for approaching limits (fire events)
        $this->checkThresholds($request, $limits, $quota);

        // Process the request
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->releaseConcurrencySlot($request);

            throw $e;
        }

        // Add rate limit headers
        return $this->addHeaders($response, $limits, $quota);
    }

    /**
     * Release the concurrency slot and count the response size against the
     * daily bandwidth quota once the response has been sent.
     */
    public function terminate(Request $request, SymfonyResponse $response): void
    {
        $this->releaseConcurrencySlot($request);
        $this->recordBandwidth($request, $response);
    }

    /**
     * Add the response size to the daily bandwidth counter of the request, if any.
     */
    protected function recordBandwidth(Request $request, SymfonyResponse $response): void
    {
        $key = $request->attributes->get(self::BANDWIDTH_ATTRIBUTE);
        $request->attributes->remove(self::BANDWIDTH_ATTRIBUTE);

        if (is_string($key) && $key !== '') {
            $this->manager->consumeBandwidth($key, $this->measureResponseSize($response));
        }
    }

    /**
     * Get the size of the response body in bytes.
     *
     * The Content-Length header is used when present. Otherwise the size of the
     * file (file responses) or of the content is used. Streamed responses
     * without a Content-Length header count as 0.
     */
    protected function measureResponseSize(SymfonyResponse $response): int
    {
        $length = $response->headers->get('Content-Length');
        if (is_numeric($length)) {
            return max(0, (int) $length);
        }

        if ($response instanceof BinaryFileResponse) {
            $size = $response->getFile()->getSize();

            return is_int($size) ? $size : 0;
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : 0;
    }

    /**
     * Release the concurrency slot held by the request, if any.
     */
    protected function releaseConcurrencySlot(Request $request): void
    {
        $slot = $request->attributes->get(self::CONCURRENCY_ATTRIBUTE);
        $request->attributes->remove(self::CONCURRENCY_ATTRIBUTE);

        if (is_array($slot) && is_string($slot['key'] ?? null) && is_string($slot['token'] ?? null)) {
            $this->manager->releaseConcurrencySlot($slot['key'], $slot['token']);
        }
    }

    /**
     * Build the response returned when the concurrent requests limit is reached.
     */
    protected function buildConcurrencyLimitedResponse(Request $request, mixed $limits, int $limit): JsonResponse
    {
        $retryAfter = 1;

        event(new RateLimitExceeded(
            key: $this->getKeyForRequest($request),
            plan: $limits->plan,
            limitType: 'concurrent',
            limit: $limit,
            attempted: $limit + 1,
            user: $request->user(),
            request: $request,
            retryAfter: $retryAfter,
        ));

        $response = [
            'success' => false,
            'error' => [
                'code' => 'CONCURRENT_LIMIT_EXCEEDED',
                'message' => "Too many concurrent requests. Please retry after {$retryAfter} second.",
                'type' => 'rate_limit',
                'limit_type' => 'concurrent',
                'limit' => $limit,
                'retry_after' => $retryAfter,
            ],
        ];

        if (config('throttle-smart.response.detailed_errors', false)) {
            $response['error']['plan'] = $limits->plan;
            $response['error']['upgrade_url'] = config('app.url').'/pricing';
        }

        return response()->json($response, 429, [
            'Retry-After' => $retryAfter,
        ]);
    }

    /**
     * Build the response returned when the daily bandwidth quota is used up.
     *
     * @param  array{limit: int|null, used: int, remaining: int|null, exceeded: bool, key: string|null, resets_at: Carbon}  $bandwidth
     */
    protected function buildBandwidthExceededResponse(Request $request, array $bandwidth): JsonResponse
    {
        $resetsAt = $bandwidth['resets_at'];
        $retryAfter = max(1, (int) ceil(Carbon::now()->diffInSeconds($resetsAt, true)));
        $limit = $bandwidth['limit'] ?? 0;
        $user = $request->user();

        if ($user instanceof User) {
            event(new QuotaExceeded(
                user: $user,
                quotaType: 'bandwidth',
                limit: $limit,
                used: $bandwidth['used'],
                resetsAt: $resetsAt,
            ));
        }

        $response = [
            'success' => false,
            'error' => [
                'code' => 'BANDWIDTH_EXCEEDED',
                'message' => "Daily bandwidth quota exceeded. Please retry after {$retryAfter} seconds.",
                'type' => 'quota',
                'quota_type' => 'bandwidth',
                'limit' => $limit,
                'used' => $bandwidth['used'],
                'resets_at' => $resetsAt->toIso8601String(),
                'retry_after' => $retryAfter,
            ],
        ];

        if (config('throttle-smart.response.detailed_errors', false)) {
            $response['error']['upgrade_url'] = config('app.url').'/pricing';
        }

        return response()->json($response, 429, [
            'Retry-After' => $retryAfter,
        ]);
    }

    /**
     * Build the rate limited response.
     */
    protected function buildRateLimitedResponse(Request $request, mixed $limits): JsonResponse
    {
        $retryAfter = $limits->retryAfter ?? 60;
        $limitType = $limits->limitedBy ?? 'minute';
        $primaryLimit = $limits->getPrimaryLimit();

        // Fire event
        event(new RateLimitExceeded(
            key: $this->getKeyForRequest($request),
            plan: $limits->plan,
            limitType: $limitType,
            limit: $primaryLimit['limit'],
            attempted: $primaryLimit['limit'] + 1,
            user: $request->user(),
            request: $request,
            retryAfter: $retryAfter,
        ));

        $response = [
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => "Too many requests. Please retry after {$retryAfter} seconds.",
                'type' => 'rate_limit',
                'limit_type' => $limitType,
                'limit' => $primaryLimit['limit'],
                'retry_after' => $retryAfter,
            ],
        ];

        if (config('throttle-smart.response.detailed_errors', false)) {
            $response['error']['plan'] = $limits->plan;
            $response['error']['upgrade_url'] = config('app.url').'/pricing';
        }

        return response()->json($response, 429, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $primaryLimit['limit'],
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => $primaryLimit['reset'],
        ]);
    }

    /**
     * Build the quota exceeded response.
     */
    protected function buildQuotaExceededResponse(Request $request, mixed $quota): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            event(new QuotaExceeded(
                user: $user,
                quotaType: 'monthly',
                limit: $quota->monthly['limit'] ?? 0,
                used: $quota->monthly['used'] ?? 0,
                resetsAt: $quota->resetsAt,
            ));
        }

        $response = [
            'success' => false,
            'error' => [
                'code' => 'QUOTA_EXCEEDED',
                'message' => sprintf(
                    'Monthly API quota exceeded. Quota resets on %s.',
                    $quota->resetsAt?->format('F j, Y') ?? 'next billing period'
                ),
                'type' => 'quota',
                'quota_type' => 'monthly',
                'limit' => $quota->monthly['limit'] ?? 0,
                'used' => $quota->monthly['used'] ?? 0,
                'resets_at' => $quota->resetsAt?->toIso8601String(),
            ],
        ];

        if (config('throttle-smart.response.detailed_errors', false)) {
            $response['error']['upgrade_url'] = config('app.url').'/pricing';
        }

        return response()->json($response, 429, [
            'X-Quota-Limit' => $quota->monthly['limit'] ?? 0,
            'X-Quota-Remaining' => 0,
            'X-Quota-Reset' => $quota->resetsAt?->timestamp ?? 0,
        ]);
    }

    /**
     * Check thresholds and fire warning events.
     */
    protected function checkThresholds(Request $request, mixed $limits, mixed $quota): void
    {
        $alertConfig = config('throttle-smart.alerts', []);
        if (! ($alertConfig['enabled'] ?? false)) {
            return;
        }

        $thresholds = $alertConfig['thresholds'] ?? ['warning' => 80, 'critical' => 95];
        $user = $request->user();

        // Check rate limit thresholds
        $primaryLimit = $limits->getPrimaryLimit();
        if ($primaryLimit['limit'] > 0) {
            $used = $primaryLimit['limit'] - $primaryLimit['remaining'];
            $percentage = ($used / $primaryLimit['limit']) * 100;

            if ($percentage >= $thresholds['warning'] && $user) {
                $threshold = $percentage >= $thresholds['critical'] ? 'critical' : 'warning';
                $key = $this->getKeyForRequest($request);

                if ($this->claimAlert("rate_limit:{$key}:minute:{$threshold}", $alertConfig)) {
                    event(new RateLimitApproaching(
                        key: $key,
                        plan: $limits->plan,
                        limitType: 'minute',
                        limit: $primaryLimit['limit'],
                        used: $used,
                        percentage: $percentage,
                        user: $user,
                    ));
                }
            }
        }

        // Check quota thresholds
        if ($user && $quota->percentageUsed >= $thresholds['warning']) {
            $threshold = $quota->percentageUsed >= $thresholds['critical'] ? 'critical' : 'warning';

            if (! $this->claimAlert("quota:{$this->getKeyForRequest($request)}:monthly:{$threshold}", $alertConfig)) {
                return;
            }

            event(new QuotaThresholdReached(
                user: $user,
                quotaType: 'monthly',
                limit: $quota->monthly['limit'] ?? 0,
                used: $quota->monthly['used'] ?? 0,
                percentage: $quota->percentageUsed,
                threshold: $threshold,
            ));
        }
    }

    /**
     * Claim the right to dispatch an alert, at most once per cooldown period.
     *
     * A cache entry living for the cooldown (in minutes) marks the alert as
     * sent. A cooldown of 0 disables the deduplication. When the cache cannot
     * be reached, the alert is dispatched.
     *
     * @param  mixed  $alertConfig  The throttle-smart.alerts configuration
     */
    protected function claimAlert(string $id, mixed $alertConfig): bool
    {
        $cooldown = is_array($alertConfig) && is_numeric($alertConfig['cooldown'] ?? null)
            ? (int) $alertConfig['cooldown']
            : 15;

        if ($cooldown <= 0) {
            return true;
        }

        $store = is_array($alertConfig) ? ($alertConfig['store'] ?? null) : null;

        try {
            return Cache::store(is_string($store) && $store !== '' ? $store : null)
                ->add('throttle-smart:alert:'.$id, true, $cooldown * 60);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Add rate limit headers to the response.
     */
    protected function addHeaders(SymfonyResponse $response, mixed $limits, mixed $quota): SymfonyResponse
    {
        if (! config('throttle-smart.headers.enabled', true)) {
            return $response;
        }

        $headerConfig = config('throttle-smart.headers', []);
        $rateLimitHeaders = $headerConfig['rate_limit'] ?? [];
        $quotaHeaders = $headerConfig['quota'] ?? [];
        $planHeaders = $headerConfig['plan'] ?? [];

        $primaryLimit = $limits->getPrimaryLimit();

        // Rate limit headers
        $response->headers->set(
            $rateLimitHeaders['limit'] ?? 'X-RateLimit-Limit',
            (string) $primaryLimit['limit']
        );
        $response->headers->set(
            $rateLimitHeaders['remaining'] ?? 'X-RateLimit-Remaining',
            (string) $primaryLimit['remaining']
        );
        $response->headers->set(
            $rateLimitHeaders['reset'] ?? 'X-RateLimit-Reset',
            (string) $primaryLimit['reset']
        );
        $response->headers->set(
            $rateLimitHeaders['policy'] ?? 'X-RateLimit-Policy',
            sprintf('%d;w=60', $primaryLimit['limit'])
        );

        // Plan header
        if ($planHeaders['enabled'] ?? true) {
            $response->headers->set(
                $planHeaders['header'] ?? 'X-RateLimit-Plan',
                $limits->plan
            );
        }

        // Quota headers
        if (($quotaHeaders['enabled'] ?? true) && $quota->monthly) {
            $response->headers->set(
                $quotaHeaders['limit'] ?? 'X-Quota-Limit',
                (string) ($quota->monthly['limit'] ?? 0)
            );
            $response->headers->set(
                $quotaHeaders['remaining'] ?? 'X-Quota-Remaining',
                (string) ($quota->monthly['remaining'] ?? 0)
            );
            $response->headers->set(
                $quotaHeaders['reset'] ?? 'X-Quota-Reset',
                (string) ($quota->resetsAt?->timestamp ?? 0)
            );
        }

        return $response;
    }

    /**
     * Get the rate limit key for the request.
     */
    protected function getKeyForRequest(Request $request): string
    {
        $user = $request->user();
        if ($user) {
            return "user:{$user->id}";
        }

        return 'ip:'.$request->ip();
    }
}

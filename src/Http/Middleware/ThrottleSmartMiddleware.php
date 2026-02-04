<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Http\Middleware;

use Closure;
use Grazulex\ThrottleSmart\Events\QuotaExceeded;
use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Events\RateLimitExceeded;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ThrottleSmartMiddleware
{
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
            return $this->buildRateLimitedResponse($request, $limits);
        }

        // Check quota
        $quota = $this->manager->getQuota($request->user());
        if ($quota->isExceeded) {
            return $this->buildQuotaExceededResponse($request, $quota);
        }

        // Check for approaching limits (fire events)
        $this->checkThresholds($request, $limits, $quota);

        // Process the request
        $response = $next($request);

        // Add rate limit headers
        return $this->addHeaders($response, $limits, $quota);
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
                event(new RateLimitApproaching(
                    key: $this->getKeyForRequest($request),
                    plan: $limits->plan,
                    limitType: 'minute',
                    limit: $primaryLimit['limit'],
                    used: $used,
                    percentage: $percentage,
                    user: $user,
                ));
            }
        }

        // Check quota thresholds
        if ($user && $quota->percentageUsed >= $thresholds['warning']) {
            $threshold = $quota->percentageUsed >= $thresholds['critical'] ? 'critical' : 'warning';
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

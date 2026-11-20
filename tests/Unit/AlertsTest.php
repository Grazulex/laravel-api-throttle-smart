<?php

declare(strict_types=1);

use Grazulex\ThrottleSmart\Drivers\CacheDriver;
use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Http\Middleware\ThrottleSmartMiddleware;
use Grazulex\ThrottleSmart\Listeners\SendThrottleAlert;
use Grazulex\ThrottleSmart\Mail\ThrottleAlertMail;
use Grazulex\ThrottleSmart\Support\QuotaInfo;
use Grazulex\ThrottleSmart\Support\RateLimits;
use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config()->set('throttle-smart.alerts', [
        'enabled' => true,
        'thresholds' => ['warning' => 80, 'critical' => 95],
        'channels' => ['slack', 'mail'],
        'cooldown' => 15,
        'store' => 'array',
        'send_notifications' => false,
        'notify' => ['user' => true, 'admin' => false],
        'mail' => ['to' => null],
        'slack' => ['webhook_url' => null],
    ]);

    $this->user = (new User)->forceFill(['id' => 7, 'email' => 'user@example.com']);

    $manager = new ThrottleSmartManager(
        new CacheDriver(app('cache'), ['prefix' => 'test:', 'store' => 'array']),
        ['default_plan' => 'free', 'plans' => ['free' => []]],
    );

    $this->middleware = new class($manager) extends ThrottleSmartMiddleware
    {
        public function check(Request $request, RateLimits $limits, QuotaInfo $quota): void
        {
            $this->checkThresholds($request, $limits, $quota);
        }
    };

    $this->request = function (?User $user = null): Request {
        $request = Request::create('/api/users', 'GET', server: ['REMOTE_ADDR' => '10.0.0.3']);
        $request->setUserResolver(fn (): ?User => $user ?? $this->user);

        return $request;
    };

    $this->limits = fn (int $remaining): RateLimits => new RateLimits(
        plan: 'free',
        perMinute: ['limit' => 100, 'remaining' => $remaining, 'reset' => time() + 60],
    );

    $this->quota = fn (int $used): QuotaInfo => new QuotaInfo(
        monthly: ['limit' => 100, 'used' => $used, 'remaining' => 100 - $used],
        percentageUsed: (float) $used,
    );
});

describe('cooldown', function (): void {
    it('dispatches the same alert once per cooldown', function (): void {
        Event::fake([RateLimitApproaching::class, QuotaThresholdReached::class]);

        for ($i = 0; $i < 3; $i++) {
            $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(85));
        }

        Event::assertDispatchedTimes(RateLimitApproaching::class, 1);
        Event::assertDispatchedTimes(QuotaThresholdReached::class, 1);
    });

    it('dispatches again once the cooldown has expired', function (): void {
        Event::fake([RateLimitApproaching::class, QuotaThresholdReached::class]);

        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(85));
        $this->travel(14)->minutes();
        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(85));

        Event::assertDispatchedTimes(RateLimitApproaching::class, 1);

        $this->travel(2)->minutes();
        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(85));

        Event::assertDispatchedTimes(RateLimitApproaching::class, 2);
        Event::assertDispatchedTimes(QuotaThresholdReached::class, 2);
    });

    it('dispatches the critical alert even while the warning is cooling down', function (): void {
        Event::fake([RateLimitApproaching::class, QuotaThresholdReached::class]);

        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(85));
        $this->middleware->check(($this->request)(), ($this->limits)(2), ($this->quota)(97));
        $this->middleware->check(($this->request)(), ($this->limits)(1), ($this->quota)(98));

        Event::assertDispatchedTimes(RateLimitApproaching::class, 2);
        Event::assertDispatchedTimes(QuotaThresholdReached::class, 2);
        Event::assertDispatched(
            QuotaThresholdReached::class,
            fn (QuotaThresholdReached $event): bool => $event->threshold === 'critical'
        );
    });

    it('keeps users independent', function (): void {
        Event::fake([RateLimitApproaching::class]);

        $other = (new User)->forceFill(['id' => 8]);

        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(0));
        $this->middleware->check(($this->request)($other), ($this->limits)(15), ($this->quota)(0));

        Event::assertDispatchedTimes(RateLimitApproaching::class, 2);
    });

    it('dispatches on every request when the cooldown is 0', function (): void {
        config()->set('throttle-smart.alerts.cooldown', 0);
        Event::fake([RateLimitApproaching::class]);

        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(0));
        $this->middleware->check(($this->request)(), ($this->limits)(15), ($this->quota)(0));

        Event::assertDispatchedTimes(RateLimitApproaching::class, 2);
    });

    it('does not dispatch below the warning threshold', function (): void {
        Event::fake([RateLimitApproaching::class, QuotaThresholdReached::class]);

        $this->middleware->check(($this->request)(), ($this->limits)(50), ($this->quota)(50));

        Event::assertNotDispatched(RateLimitApproaching::class);
        Event::assertNotDispatched(QuotaThresholdReached::class);
    });
});

describe('notifications', function (): void {
    beforeEach(function (): void {
        Mail::fake();

        $this->rateEvent = new RateLimitApproaching(
            key: 'user:7',
            plan: 'free',
            limitType: 'minute',
            limit: 100,
            used: 85,
            percentage: 85.0,
            user: $this->user,
        );

        $this->quotaEvent = new QuotaThresholdReached(
            user: $this->user,
            quotaType: 'monthly',
            limit: 100,
            used: 96,
            percentage: 96.0,
            threshold: 'critical',
        );
    });

    it('sends nothing unless send_notifications is enabled', function (): void {
        Http::fake();

        (new SendThrottleAlert)->handle($this->rateEvent);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    });

    it('mails the user', function (): void {
        Http::fake();
        config()->set('throttle-smart.alerts.send_notifications', true);

        (new SendThrottleAlert)->handle($this->quotaEvent);

        Mail::assertSent(
            ThrottleAlertMail::class,
            fn (ThrottleAlertMail $mail): bool => $mail->hasTo('user@example.com')
                && str_contains($mail->alertSubject, 'critical')
                && str_contains($mail->alertSubject, 'monthly')
        );
        Mail::assertSentCount(1);
        Http::assertNothingSent();
    });

    it('mails the admins and posts to slack when admins are notified', function (): void {
        Http::fake();
        config()->set('throttle-smart.alerts.send_notifications', true);
        config()->set('throttle-smart.alerts.notify', ['user' => false, 'admin' => true]);
        config()->set('throttle-smart.alerts.mail.to', 'ops@example.com, cto@example.com');
        config()->set('throttle-smart.alerts.slack.webhook_url', 'https://hooks.slack.test/services/abc');

        (new SendThrottleAlert)->handle($this->rateEvent);

        Mail::assertSentCount(2);
        Mail::assertSent(ThrottleAlertMail::class, fn (ThrottleAlertMail $mail): bool => $mail->hasTo('ops@example.com'));
        Mail::assertSent(ThrottleAlertMail::class, fn (ThrottleAlertMail $mail): bool => $mail->hasTo('cto@example.com'));
        Mail::assertNotSent(ThrottleAlertMail::class, fn (ThrottleAlertMail $mail): bool => $mail->hasTo('user@example.com'));

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://hooks.slack.test/services/abc'
            && str_contains((string) $request['text'], 'API rate limit warning')
            && str_contains((string) $request['text'], 'Key: user:7'));
    });

    it('only uses the configured channels', function (): void {
        Http::fake();
        config()->set('throttle-smart.alerts.send_notifications', true);
        config()->set('throttle-smart.alerts.channels', ['slack']);
        config()->set('throttle-smart.alerts.notify', ['user' => true, 'admin' => true]);
        config()->set('throttle-smart.alerts.mail.to', ['ops@example.com']);
        config()->set('throttle-smart.alerts.slack.webhook_url', 'https://hooks.slack.test/services/abc');

        (new SendThrottleAlert)->handle($this->rateEvent);

        Mail::assertNothingSent();
        Http::assertSentCount(1);
    });

    it('does not break when slack fails', function (): void {
        Http::fake(['*' => Http::response('error', 500)]);
        config()->set('throttle-smart.alerts.send_notifications', true);
        config()->set('throttle-smart.alerts.channels', ['slack']);
        config()->set('throttle-smart.alerts.notify', ['user' => false, 'admin' => true]);
        config()->set('throttle-smart.alerts.slack.webhook_url', 'https://hooks.slack.test/services/abc');

        (new SendThrottleAlert)->handle($this->rateEvent);

        Http::assertSentCount(1);
    });

    it('is registered for the alert events', function (): void {
        config()->set('throttle-smart.alerts.send_notifications', true);

        event($this->rateEvent);
        event($this->quotaEvent);

        Mail::assertSentCount(2);
    });

    it('renders the alert details', function (): void {
        $mail = new ThrottleAlertMail('Subject <b>', ['Used: 1 of 2']);

        $mail->assertSeeInHtml('Used: 1 of 2')
            ->assertSeeInHtml('Subject &lt;b&gt;', false);
    });
});

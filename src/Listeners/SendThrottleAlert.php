<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Listeners;

use Grazulex\ThrottleSmart\Events\QuotaThresholdReached;
use Grazulex\ThrottleSmart\Events\RateLimitApproaching;
use Grazulex\ThrottleSmart\Mail\ThrottleAlertMail;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers quota and rate limit alerts through the configured channels.
 *
 * Only active when both throttle-smart.alerts.enabled and
 * throttle-smart.alerts.send_notifications are true. Delivery failures are
 * reported and never break the request.
 */
class SendThrottleAlert
{
    public function handle(RateLimitApproaching|QuotaThresholdReached $event): void
    {
        $config = config('throttle-smart.alerts', []);

        if (! is_array($config) || ! ($config['enabled'] ?? false) || ! ($config['send_notifications'] ?? false)) {
            return;
        }

        $channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];
        $notify = is_array($config['notify'] ?? null) ? $config['notify'] : [];
        $notifyUser = (bool) ($notify['user'] ?? false);
        $notifyAdmin = (bool) ($notify['admin'] ?? false);

        [$subject, $lines] = $this->describe($event, $config);

        if (in_array('mail', $channels, true)) {
            $recipients = [];

            if ($notifyUser) {
                $email = $this->userEmail($event->user);
                if ($email !== null) {
                    $recipients[] = $email;
                }
            }

            if ($notifyAdmin) {
                $recipients = [...$recipients, ...$this->adminEmails($config)];
            }

            $this->sendMail(array_values(array_unique($recipients)), $subject, $lines);
        }

        if ($notifyAdmin && in_array('slack', $channels, true)) {
            $slack = is_array($config['slack'] ?? null) ? $config['slack'] : [];
            $webhook = $slack['webhook_url'] ?? null;

            if (is_string($webhook) && $webhook !== '') {
                $this->sendSlack($webhook, $subject, $lines);
            }
        }
    }

    /**
     * Build the subject and the detail lines of the alert.
     *
     * @param  array<mixed>  $config
     * @return array{0: string, 1: list<string>}
     */
    protected function describe(RateLimitApproaching|QuotaThresholdReached $event, array $config): array
    {
        $userKey = $event->user?->getKey();
        $userLine = is_int($userKey) || is_string($userKey) ? 'User: '.$userKey : null;
        $percentage = round($event->percentage, 1);

        if ($event instanceof QuotaThresholdReached) {
            return [
                sprintf('API quota %s: %s%% of the %s quota used', $event->threshold, $percentage, $event->quotaType),
                array_values(array_filter([
                    $userLine,
                    'Quota: '.$event->quotaType,
                    sprintf('Used: %d of %d (%s%%)', $event->used, $event->limit, $percentage),
                    'Threshold: '.$event->threshold,
                ])),
            ];
        }

        $thresholds = is_array($config['thresholds'] ?? null) ? $config['thresholds'] : [];
        $critical = is_numeric($thresholds['critical'] ?? null) ? (float) $thresholds['critical'] : 95.0;
        $threshold = $event->percentage >= $critical ? 'critical' : 'warning';

        return [
            sprintf('API rate limit %s: %s%% of the %s limit used', $threshold, $percentage, $event->limitType),
            array_values(array_filter([
                $userLine,
                'Key: '.$event->key,
                'Plan: '.$event->plan,
                'Limit type: '.$event->limitType,
                sprintf('Used: %d of %d (%s%%)', $event->used, $event->limit, $percentage),
                'Threshold: '.$threshold,
            ])),
        ];
    }

    protected function userEmail(?User $user): ?string
    {
        $email = $user?->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Get the admin addresses from alerts.mail.to (array or comma separated string).
     *
     * @param  array<mixed>  $config
     * @return list<string>
     */
    protected function adminEmails(array $config): array
    {
        $mail = is_array($config['mail'] ?? null) ? $config['mail'] : [];
        $to = $mail['to'] ?? null;

        if (is_string($to)) {
            $to = explode(',', $to);
        }

        if (! is_array($to)) {
            return [];
        }

        $emails = [];
        foreach ($to as $address) {
            if (is_string($address) && trim($address) !== '') {
                $emails[] = trim($address);
            }
        }

        return $emails;
    }

    /**
     * Send one mail per recipient so that addresses are never shared.
     *
     * @param  list<string>  $recipients
     * @param  list<string>  $lines
     */
    protected function sendMail(array $recipients, string $subject, array $lines): void
    {
        if ($recipients === [] || ! app()->bound('mail.manager')) {
            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new ThrottleAlertMail($subject, $lines));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Post the alert to a Slack incoming webhook.
     *
     * @param  list<string>  $lines
     */
    protected function sendSlack(string $webhook, string $subject, array $lines): void
    {
        try {
            Http::timeout(5)->post($webhook, [
                'text' => $subject."\n".implode("\n", $lines),
            ])->throw();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Console\Command;

class ThrottleUserCommand extends Command
{
    protected $signature = 'throttle:user {id : The user ID}';

    protected $description = 'View rate limits for a specific user';

    public function handle(ThrottleSmartManager $manager): int
    {
        $userId = (int) $this->argument('id');

        // Create a mock user object
        $user = new class($userId)
        {
            public function __construct(public int $id) {}
        };

        $limits = $manager->getLimits($user);
        $quota = $manager->getQuota($user);

        $this->info("User #{$userId} ({$limits->plan} Plan)");
        $this->newLine();

        $headers = ['Limit', 'Max', 'Remaining', 'Reset'];
        $rows = [];

        if ($limits->perSecond) {
            $rows[] = ['Per Second', $limits->perSecond['limit'], $limits->perSecond['remaining'], date('H:i:s', $limits->perSecond['reset'])];
        }
        if ($limits->perMinute) {
            $rows[] = ['Per Minute', $limits->perMinute['limit'], $limits->perMinute['remaining'], date('H:i:s', $limits->perMinute['reset'])];
        }
        if ($limits->perHour) {
            $rows[] = ['Per Hour', $limits->perHour['limit'], $limits->perHour['remaining'], date('H:i:s', $limits->perHour['reset'])];
        }
        if ($limits->perDay) {
            $rows[] = ['Per Day', $limits->perDay['limit'], $limits->perDay['remaining'], date('Y-m-d', $limits->perDay['reset'])];
        }

        $this->table($headers, $rows);

        if ($quota->monthly) {
            $this->newLine();
            $this->info('Monthly Quota');
            $this->table(
                ['Limit', 'Used', 'Remaining', 'Usage %'],
                [[
                    $quota->monthly['limit'],
                    $quota->monthly['used'],
                    $quota->monthly['remaining'],
                    round($quota->percentageUsed, 2).'%',
                ]]
            );
        }

        return Command::SUCCESS;
    }
}

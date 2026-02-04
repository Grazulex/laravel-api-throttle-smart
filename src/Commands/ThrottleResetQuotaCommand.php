<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Console\Command;

class ThrottleResetQuotaCommand extends Command
{
    protected $signature = 'throttle:reset-quota
                            {--user= : Reset quota for a specific user ID}
                            {--type=monthly : Quota type (monthly, daily)}';

    protected $description = 'Reset quota for a user';

    public function handle(ThrottleSmartManager $manager): int
    {
        $userId = $this->option('user');
        $type = $this->option('type');

        if (! $userId) {
            $this->error('Please provide --user option.');

            return Command::FAILURE;
        }

        $user = new class((int) $userId)
        {
            public function __construct(public int $id) {}
        };

        $manager->resetQuota($user);
        $this->info("{$type} quota reset for user #{$userId}");

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Console\Command;

class ThrottleGrantQuotaCommand extends Command
{
    protected $signature = 'throttle:grant-quota
                            {--user= : Grant quota to a specific user ID}
                            {--amount= : Amount of quota to grant}
                            {--reason= : Reason for granting quota}';

    protected $description = 'Grant additional quota to a user';

    public function handle(ThrottleSmartManager $manager): int
    {
        $userId = $this->option('user');
        $amount = $this->option('amount');
        $reason = $this->option('reason');

        if (! $userId || ! $amount) {
            $this->error('Please provide both --user and --amount options.');

            return Command::FAILURE;
        }

        $user = new class((int) $userId)
        {
            public function __construct(public int $id) {}
        };

        $manager->addQuota($user, (int) $amount, $reason);
        $this->info("Granted {$amount} additional requests to user #{$userId}");

        if ($reason) {
            $this->info("Reason: {$reason}");
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Grazulex\ThrottleSmart\ThrottleSmartManager;
use Illuminate\Console\Command;

class ThrottleResetCommand extends Command
{
    protected $signature = 'throttle:reset
                            {--user= : Reset rate limits for a specific user ID}
                            {--key= : Reset rate limits for a specific key}';

    protected $description = 'Reset rate limits for a user or key';

    public function handle(ThrottleSmartManager $manager): int
    {
        $userId = $this->option('user');
        $key = $this->option('key');

        if (! $userId && ! $key) {
            $this->error('Please provide either --user or --key option.');

            return Command::FAILURE;
        }

        if ($key) {
            $manager->reset($key);
            $this->info("Rate limits reset for key: {$key}");
        } else {
            $manager->reset("user:{$userId}");
            $this->info("Rate limits reset for user #{$userId}");
        }

        return Command::SUCCESS;
    }
}

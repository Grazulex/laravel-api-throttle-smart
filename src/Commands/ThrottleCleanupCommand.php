<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Grazulex\ThrottleSmart\Contracts\StorageDriverInterface;
use Illuminate\Console\Command;

class ThrottleCleanupCommand extends Command
{
    protected $signature = 'throttle:cleanup
                            {--older-than=90 : Delete data older than X days}';

    protected $description = 'Cleanup old rate limit and analytics data';

    public function handle(StorageDriverInterface $driver): int
    {
        $days = (int) $this->option('older-than');

        $this->info("Cleaning up data older than {$days} days...");

        $deleted = $driver->cleanup($days);

        $this->info("Cleaned up {$deleted} records.");

        return Command::SUCCESS;
    }
}

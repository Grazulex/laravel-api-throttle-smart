<?php

declare(strict_types=1);

namespace Grazulex\ThrottleSmart\Commands;

use Illuminate\Console\Command;

class ThrottleStatusCommand extends Command
{
    protected $signature = 'throttle:status';

    protected $description = 'View current rate limit status overview';

    public function handle(): int
    {
        $this->info('Rate Limit Status');
        $this->newLine();

        $plans = config('throttle-smart.plans', []);

        $headers = ['Plan', 'Per Minute', 'Per Hour', 'Per Month'];
        $rows = [];

        foreach ($plans as $name => $config) {
            $rows[] = [
                ucfirst($name),
                $config['requests_per_minute'] ?? 'Unlimited',
                $config['requests_per_hour'] ?? 'Unlimited',
                $config['requests_per_month'] ?? 'Unlimited',
            ];
        }

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}

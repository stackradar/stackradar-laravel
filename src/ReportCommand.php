<?php

declare(strict_types=1);

namespace StackRadar\Laravel;

use Illuminate\Console\Command;

final class ReportCommand extends Command
{
    protected $signature = 'stackradar:report';

    protected $description = 'Report this CLI runtime to StackRadar now';

    public function handle(Reporter $reporter): int
    {
        if (! $reporter->reportIfDue('cli', force: true)) {
            $this->error('Report not sent. Check StackRadar configuration or enable STACKRADAR_DEBUG for diagnostics.');

            return self::FAILURE;
        }

        $this->info('Runtime inventory reported.');

        return self::SUCCESS;
    }
}

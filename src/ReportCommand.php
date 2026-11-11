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
        $accepted = $reporter->reportIfDue('cli', force: true);
        $failure = $reporter->lastFailure();
        if (! $accepted || $failure !== null) {
            $reason = $failure['reason'] ?? 'report_not_sent';
            $status = ($failure['status'] ?? 0) > 0 ? 'HTTP '.$failure['status'].' ' : '';
            $this->error(($accepted ? 'Check-in accepted; inventory unavailable: ' : 'Report not sent: ').$status.$reason);

            return self::FAILURE;
        }

        $this->info('Runtime inventory reported.');

        return self::SUCCESS;
    }
}

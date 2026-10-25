<?php

namespace Bernskiold\LaravelBackgroundJobs\Console;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes the logs of old settled jobs, and keeps the jobs.
 *
 * The "retention.logs" config sets the age, in days, for finished jobs and for
 * failed jobs. The children of a run use their own status and age.
 */
#[AsCommand(name: 'background-jobs:prune-logs')]
class PruneBackgroundJobLogsCommand extends Command
{
    protected $signature = 'background-jobs:prune-logs';

    protected $description = 'Delete the logs of background jobs that are older than the log retention.';

    public function handle(): int
    {
        $days = config('background-jobs.retention.logs.days');
        $failedDays = config('background-jobs.retention.logs.failed_days');

        if ($days === null && $failedDays === null) {
            $this->components->info('There is no log retention. No logs were deleted.');

            return self::SUCCESS;
        }

        $store = BackgroundJobs::logs()->store();
        $jobs = 0;

        BackgroundJobs::model()::query()
            ->olderThanRetention($days, $failedDays)
            ->select('id')
            ->chunkById(500, function ($chunk) use ($store, &$jobs) {
                $store->deleteForJobs($chunk->modelKeys());
                $jobs += $chunk->count();
            });

        $this->components->info("Deleted the logs of {$jobs} job(s).");

        return self::SUCCESS;
    }
}

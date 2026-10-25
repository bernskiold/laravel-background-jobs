<?php

namespace Bernskiold\LaravelBackgroundJobs\Console;

use Bernskiold\LaravelBackgroundJobs\Actions\SettleBackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The safety net for background job runs.
 *
 * Locks cannot prevent one problem of each count-to-N design. A child that
 * never gets a final status keeps its run unsettled, and then the owner gets no
 * notification. Examples are a worker that runs out of memory, a cancelled
 * batch, or a lost roll-up. Schedule this command to find and settle such runs.
 */
#[AsCommand(name: 'background-jobs:reconcile-runs')]
class ReconcileBackgroundJobRunsCommand extends Command
{
    protected $signature = 'background-jobs:reconcile-runs {--minutes=15 : Only check runs that were sealed at least this number of minutes ago.}';

    protected $description = 'Count the children of unsettled background job runs again, and settle the runs that are complete.';

    public function handle(SettleBackgroundJobRun $settle): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $runs = BackgroundJobs::model()::query()
            ->unsettledRuns()
            ->where('sealed_at', '<=', $cutoff)
            ->get();

        if ($runs->isEmpty()) {
            $this->components->info('No unsettled runs to reconcile.');

            return self::SUCCESS;
        }

        $settled = 0;

        foreach ($runs as $run) {
            $counts = $this->countChildren($run);

            // Count again, and do not trust the counters. This repairs a lost
            // roll-up and a deleted child. The GROUP BY is safe here, because
            // the command runs without a transaction and reads current data.
            BackgroundJobs::table()
                ->where('id', $run->getKey())
                ->update([
                    'children_succeeded' => $counts['succeeded'],
                    'children_failed' => $counts['failed'],
                    'updated_at' => now(),
                ]);

            if ($settle->execute($run->getKey())) {
                $settled++;
                $this->components->info("Settled run #{$run->getKey()} ({$counts['succeeded']} succeeded, {$counts['failed']} failed).");

                continue;
            }

            $this->reportStillRunning($run, $counts);
        }

        $this->components->info("Reconciled {$runs->count()} run(s), settled {$settled}.");

        return self::SUCCESS;
    }

    /**
     * @return array{succeeded: int, failed: int, outstanding: int}
     */
    protected function countChildren(BackgroundJob $run): array
    {
        $byStatus = BackgroundJobs::table()
            ->where('parent_id', $run->getKey())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (BackgroundJobStatus $status): int => (int) $byStatus->get($status->value, 0);

        return [
            'succeeded' => $count(BackgroundJobStatus::Finished),
            'failed' => $count(BackgroundJobStatus::Failed) + $count(BackgroundJobStatus::CompletedWithErrors),
            'outstanding' => $count(BackgroundJobStatus::Waiting) + $count(BackgroundJobStatus::Processing),
        ];
    }

    /**
     * A run with children that still run stays as it is. To fail it can stop
     * long work that is correct. The command only reports a run when it is so
     * old that something is clearly wrong.
     *
     * @param  array{succeeded: int, failed: int, outstanding: int}  $counts
     */
    protected function reportStillRunning(BackgroundJob $run, array $counts): void
    {
        if ($run->sealed_at === null || $run->sealed_at->diffInHours(now()) < 24) {
            return;
        }

        $this->components->warn(
            "Run #{$run->getKey()} has been running for more than 24 hours with {$counts['outstanding']} child job(s) outstanding."
        );
    }
}

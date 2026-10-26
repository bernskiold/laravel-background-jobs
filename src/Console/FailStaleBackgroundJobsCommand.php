<?php

namespace Bernskiold\LaravelBackgroundJobs\Console;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Gives a final status to jobs that stopped without one.
 *
 * When a worker stops during a job, for example because it runs out of memory,
 * nothing changes the status of the job. Without this command, the job stays
 * at "processing" forever.
 *
 * A job is stale when it is waiting or processing and has had no update in the
 * stale window. A status change, a log entry, a meta change and progress each
 * update the job. The command:
 *
 * - fails a stale standalone job or child, so its owner or its run learns of it,
 * - seals a stale run that was never sealed, so it can settle with the
 *   children that it has.
 *
 * A sealed run settles when its children settle. The command does not change it.
 */
#[AsCommand(name: 'background-jobs:fail-stale')]
class FailStaleBackgroundJobsCommand extends Command
{
    protected $signature = 'background-jobs:fail-stale {--hours= : The stale window in hours. The default is the "stale_after_hours" config.}';

    protected $description = 'Fail the background jobs that stopped without a final status, and seal the runs that were never sealed.';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? BackgroundJobs::staleAfterHours());

        $failed = 0;

        $this->staleJobs($hours)->chunkById(200, function ($jobs) use ($hours, &$failed) {
            foreach ($jobs as $job) {
                $job->logChannel()->error("The job had no update for {$hours} hours. The worker probably stopped.");
                $job->fail(__('The job stopped without a final status. It had no update for :hours hours.', ['hours' => $hours]));
                $failed++;
            }
        });

        $sealed = 0;

        $this->unsealedRuns($hours)->chunkById(200, function ($runs) use (&$sealed) {
            foreach ($runs as $run) {
                $run->logChannel()->warning('The run was never sealed. It is sealed now with the children that it has.');
                BackgroundJobRun::for($run)->seal();
                $sealed++;
            }
        });

        $this->components->info("Failed {$failed} stale job(s) and sealed {$sealed} run(s).");

        return self::SUCCESS;
    }

    /**
     * Standalone jobs and children. A run has children or an expected count.
     *
     * @return Builder<BackgroundJob>
     */
    protected function staleJobs(int $hours): Builder
    {
        return BackgroundJobs::model()::query()
            ->stale($hours)
            ->whereNull('children_expected')
            ->whereDoesntHave('children');
    }

    /**
     * @return Builder<BackgroundJob>
     */
    protected function unsealedRuns(int $hours): Builder
    {
        return BackgroundJobs::model()::query()
            ->stale($hours)
            ->whereNull('children_expected')
            ->whereHas('children');
    }
}

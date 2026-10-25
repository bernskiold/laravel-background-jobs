<?php

namespace Bernskiold\LaravelBackgroundJobs\Listeners;

use Bernskiold\LaravelBackgroundJobs\Actions\MarkBackgroundJobRunAsProcessing;
use Bernskiold\LaravelBackgroundJobs\Actions\RollUpBackgroundJobChild;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;

/**
 * Adds the status change of a child to its run.
 *
 * This listener is not queued on purpose. It only does some small indexed
 * updates. A queue adds a point of failure: when one message is lost, the run
 * stays at "processing" forever. The notification that follows is queued.
 */
class RollUpParentBackgroundJob
{
    public function __construct(
        protected RollUpBackgroundJobChild $rollUp,
        protected MarkBackgroundJobRunAsProcessing $markProcessing,
    ) {}

    public function handle(BackgroundJobChangedStatus $event): void
    {
        $job = BackgroundJobs::model()::query()->find($event->backgroundJobId);

        // Standalone jobs, runs, and the event of the run itself come here
        // too. They have nothing to roll up.
        if ($job === null || $job->parent_id === null) {
            return;
        }

        if ($job->status->hasFinishedRunning()) {
            $this->rollUp->execute($job);

            return;
        }

        $this->markProcessing->execute($job->parent_id);
    }
}

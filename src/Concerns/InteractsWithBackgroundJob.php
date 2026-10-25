<?php

namespace Bernskiold\LaravelBackgroundJobs\Concerns;

use Bernskiold\LaravelBackgroundJobs\Jobs\Middleware\TrackBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Throwable;

/**
 * Use this trait on a queued job that has a "backgroundJob" property. Add the
 * {@see TrackBackgroundJob} middleware to the job too.
 *
 * The trait fails the background job when the queued job fails for good, also
 * when the queue stops the job after a timeout. Then the middleware does not
 * see the failure. If your job has its own failed() method, call
 * failBackgroundJob() from it.
 */
trait InteractsWithBackgroundJob
{
    public function backgroundJob(): ?BackgroundJob
    {
        return $this->backgroundJob ?? null;
    }

    public function failed(?Throwable $exception = null): void
    {
        $this->failBackgroundJob($exception);
    }

    protected function failBackgroundJob(?Throwable $exception = null): void
    {
        $backgroundJob = $this->backgroundJob()?->fresh();

        if ($backgroundJob === null || $backgroundJob->status->hasFinishedRunning()) {
            return;
        }

        $reason = $exception?->getMessage() ?: __('The queued job failed.');

        $backgroundJob->logChannel()->error($reason);
        $backgroundJob->fail($reason);
    }
}

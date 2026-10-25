<?php

namespace Bernskiold\LaravelBackgroundJobs\Jobs\Middleware;

use Bernskiold\LaravelBackgroundJobs\Concerns\InteractsWithBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Contracts\HasBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Closure;
use Throwable;

/**
 * Keeps the status of a background job in step with the queued job that does
 * its work.
 *
 * - When the background job already failed, the queued job does not run. In a
 *   batch, one failed job thus stops the jobs that come after it.
 * - Before the queued job runs, the background job becomes "processing".
 * - When the queued job throws, the background job fails with the message,
 *   and the exception goes on to the queue. A retry then does not run,
 *   because the background job has failed. For a job that the queue must
 *   retry, set $failOnException to false. Then the failed() method of
 *   {@see InteractsWithBackgroundJob}
 *   fails the background job after the last attempt.
 * - When you set $finish, the background job finishes after the queued job.
 *   Leave it off when a batch or a later job finishes the background job.
 */
class TrackBackgroundJob
{
    public function __construct(
        public bool $finish = false,
        public bool $failOnException = true,
    ) {}

    public static function andFinish(): self
    {
        return new self(finish: true);
    }

    public function handle(object $job, Closure $next): mixed
    {
        $backgroundJob = $this->backgroundJobFor($job)?->fresh();

        if ($backgroundJob === null) {
            return $next($job);
        }

        if ($backgroundJob->status === BackgroundJobStatus::Failed) {
            $backgroundJob->logChannel()->info('Skipped '.class_basename($job).', because the background job has failed.');

            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return null;
        }

        $backgroundJob->start();

        try {
            $result = $next($job);
        } catch (Throwable $exception) {
            if ($this->failOnException && ! $backgroundJob->refresh()->status->hasFinishedRunning()) {
                $backgroundJob->logChannel()->error($exception->getMessage());
                $backgroundJob->fail($exception->getMessage());
            }

            throw $exception;
        }

        if ($this->finish && ! $backgroundJob->refresh()->status->hasFinishedRunning()) {
            $backgroundJob->finish();
        }

        return $result;
    }

    protected function backgroundJobFor(object $job): ?BackgroundJob
    {
        if ($job instanceof HasBackgroundJob) {
            return $job->backgroundJob();
        }

        $backgroundJob = $job->backgroundJob ?? null;

        return $backgroundJob instanceof BackgroundJob ? $backgroundJob : null;
    }
}

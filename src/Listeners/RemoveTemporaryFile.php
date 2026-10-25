<?php

namespace Bernskiold\LaravelBackgroundJobs\Listeners;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Support\TemporaryFile;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Deletes the temporary file of a job when the job is finished or failed. An
 * upload that a job imports is an example.
 *
 * A file on a shared disk can be deleted from any server. A local file path
 * from an earlier version is only deleted when this listener runs on the
 * server that has the file.
 */
class RemoveTemporaryFile implements ShouldQueue
{
    public function handle(BackgroundJobChangedStatus $event): void
    {
        $this->file($event)?->delete();
    }

    public function shouldQueue(BackgroundJobChangedStatus $event): bool
    {
        if (! config('background-jobs.remove_temporary_files', true)) {
            return false;
        }

        return $event->to->hasFinishedRunning() && $this->file($event) !== null;
    }

    protected function file(BackgroundJobChangedStatus $event): ?TemporaryFile
    {
        return BackgroundJobs::model()::query()->find($event->backgroundJobId)?->temporaryFile();
    }
}

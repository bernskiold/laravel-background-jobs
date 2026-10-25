<?php

namespace Bernskiold\LaravelBackgroundJobs\Listeners;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

/**
 * Deletes the local file in the "file_path" item of the meta when the job is
 * finished or failed. An upload that a job imports is an example.
 */
class RemoveTemporaryFile implements ShouldQueue
{
    public function handle(BackgroundJobChangedStatus $event): void
    {
        $path = $this->filePath($event);

        if ($path !== null) {
            File::delete($path);
        }
    }

    public function shouldQueue(BackgroundJobChangedStatus $event): bool
    {
        if (! config('background-jobs.remove_temporary_files', true)) {
            return false;
        }

        return $event->to->hasFinishedRunning() && $this->filePath($event) !== null;
    }

    protected function filePath(BackgroundJobChangedStatus $event): ?string
    {
        /** @var BackgroundJob|null $job */
        $job = BackgroundJobs::model()::query()->find($event->backgroundJobId);

        $path = $job?->meta['file_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Listeners;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Notifies the owner of a job when the job is finished or failed.
 *
 * A run sends one notification for all of its children. The children of a run
 * do not send notifications. A job without an owner does not send a
 * notification.
 */
class NotifyOnFinish implements ShouldQueue
{
    public function handle(BackgroundJobChangedStatus $event): void
    {
        $job = $this->find($event);
        $user = $job?->user;

        if ($job === null || $user === null || ! method_exists($user, 'notify')) {
            return;
        }

        $notification = match (true) {
            $job->isRun() => config('background-jobs.notifications.run_completed'),
            $job->status === BackgroundJobStatus::Failed => config('background-jobs.notifications.failed'),
            default => config('background-jobs.notifications.succeeded'),
        };

        if ($notification === null) {
            return;
        }

        $user->notify(new $notification($job));
    }

    public function shouldQueue(BackgroundJobChangedStatus $event): bool
    {
        if (! config('background-jobs.notifications.enabled', true)) {
            return false;
        }

        if ($event->to->isRunning()) {
            return false;
        }

        $job = $this->find($event);

        if ($job === null || $job->isChild() || $job->user_id === null) {
            return false;
        }

        return $this->userCanView($job);
    }

    protected function find(BackgroundJobChangedStatus $event): ?BackgroundJob
    {
        return BackgroundJobs::model()::query()->find($event->backgroundJobId);
    }

    /**
     * The owner must still be able to see the job, because the notification
     * links to it. When the application has no policy for jobs and no gate for
     * the ability, the package does not check.
     */
    protected function userCanView(BackgroundJob $job): bool
    {
        $user = $job->user;

        if (! $user instanceof Model) {
            return false;
        }

        $ability = $job->viewAbility();

        if (Gate::getPolicyFor($job) === null && ! Gate::has($ability)) {
            return true;
        }

        return Gate::forUser($user)->allows($ability, $job);
    }
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Listeners;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobOutcome;
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
 * do not send notifications. A job without an owner, or a job that was made
 * with "notify: false", does not send a notification. See BackgroundJobs::notificationFor() for how the notification
 * is chosen.
 */
class NotifyOnFinish implements ShouldQueue
{
    public function handle(BackgroundJobChangedStatus $event): void
    {
        $job = $this->find($event);
        $user = $job?->user;

        if ($job === null || $user === null || ! $job->notifiesOwner() || ! method_exists($user, 'notify')) {
            return;
        }

        $notification = BackgroundJobs::notificationFor($job, $this->outcome($job));

        if ($notification !== null) {
            $user->notify($notification);
        }
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

        // The setting of the job comes first. The caller asked for no
        // notification, so the hooks and the config do not apply.
        if ($job === null || $job->isChild() || ! $job->notifiesOwner()) {
            return false;
        }

        if (BackgroundJobs::notificationFor($job, $this->outcome($job)) === null) {
            return false;
        }

        return $this->userCanView($job);
    }

    protected function outcome(BackgroundJob $job): BackgroundJobOutcome
    {
        return match (true) {
            $job->isRun() => BackgroundJobOutcome::RunCompleted,
            $job->status === BackgroundJobStatus::Failed => BackgroundJobOutcome::Failed,
            default => BackgroundJobOutcome::Succeeded,
        };
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

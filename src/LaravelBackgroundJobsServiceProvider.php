<?php

namespace Bernskiold\LaravelBackgroundJobs;

use Bernskiold\LaravelBackgroundJobs\Console\ReconcileBackgroundJobRunsCommand;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Listeners\NotifyOnFinish;
use Bernskiold\LaravelBackgroundJobs\Listeners\RemoveTemporaryFile;
use Bernskiold\LaravelBackgroundJobs\Listeners\RollUpParentBackgroundJob;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LaravelBackgroundJobsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/background-jobs.php', 'background-jobs'
        );
    }

    public function boot(): void
    {
        AboutCommand::add('Laravel Background Jobs', fn () => ['Version' => '0.1.0']);

        $this->publishes([
            __DIR__.'/../config/background-jobs.php' => config_path('background-jobs.php'),
        ], 'background-jobs-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_background_jobs_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_background_jobs_table.php'),
        ], 'background-jobs-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileBackgroundJobRunsCommand::class,
            ]);
        }

        $this->registerListeners();
        $this->registerBroadcastChannels();
    }

    /**
     * The order is important. The roll-up must run before the notification:
     * when the roll-up settles a run, the run sends its own status event, and
     * that event sends the one notification for the run.
     */
    protected function registerListeners(): void
    {
        Event::listen(BackgroundJobChangedStatus::class, RemoveTemporaryFile::class);
        Event::listen(BackgroundJobChangedStatus::class, RollUpParentBackgroundJob::class);
        Event::listen(BackgroundJobChangedStatus::class, NotifyOnFinish::class);
    }

    /**
     * A user can listen to a job or a run when the user can see it. A user can
     * listen to the shared channel when the user can see the list of jobs.
     */
    protected function registerBroadcastChannels(): void
    {
        if (! config('background-jobs.broadcasting.enabled') || ! config('background-jobs.broadcasting.register_channels')) {
            return;
        }

        $canSee = function (Authenticatable $user, int|string $id): bool {
            $job = BackgroundJobs::model()::query()->find($id);

            return $job !== null && Gate::forUser($user)->allows($job->viewAbility(), $job);
        };

        Broadcast::channel(BackgroundJobChangedStatus::jobChannel('{id}'), $canSee);
        Broadcast::channel(BackgroundJobChangedStatus::runChannel('{id}'), $canSee);
        Broadcast::channel(
            BackgroundJobChangedStatus::listChannel(),
            fn (Authenticatable $user): bool => Gate::forUser($user)->allows('viewAny', BackgroundJobs::model()),
        );
    }
}

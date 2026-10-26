<?php

namespace Bernskiold\LaravelBackgroundJobs;

use Bernskiold\LaravelBackgroundJobs\Console\FailStaleBackgroundJobsCommand;
use Bernskiold\LaravelBackgroundJobs\Console\ImportFileLogsCommand;
use Bernskiold\LaravelBackgroundJobs\Console\PruneBackgroundJobLogsCommand;
use Bernskiold\LaravelBackgroundJobs\Console\ReconcileBackgroundJobRunsCommand;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Listeners\NotifyOnFinish;
use Bernskiold\LaravelBackgroundJobs\Listeners\RemoveTemporaryFile;
use Bernskiold\LaravelBackgroundJobs\Listeners\RollUpParentBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Logging\BackgroundJobLogs;
use Bernskiold\LaravelBackgroundJobs\Logging\DatabaseLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\FileLogStore;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LaravelBackgroundJobsServiceProvider extends ServiceProvider
{
    protected static bool $shutdownFlushRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/background-jobs.php', 'background-jobs'
        );

        $this->app->singleton(BackgroundJobLogStore::class, fn (Application $app) => $app->make(
            match ($store = config('background-jobs.logs.store', 'database')) {
                'database' => DatabaseLogStore::class,
                'file' => FileLogStore::class,
                default => $store,
            }
        ));

        $this->app->singleton(BackgroundJobLogs::class);
    }

    public function boot(): void
    {
        AboutCommand::add('Laravel Background Jobs', fn () => [
            'Version' => InstalledVersions::getPrettyVersion('bernskiold/laravel-background-jobs') ?? 'unknown',
        ]);

        $this->publishes([
            __DIR__.'/../config/background-jobs.php' => config_path('background-jobs.php'),
        ], 'background-jobs-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_background_jobs_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_background_jobs_table.php'),
            __DIR__.'/../database/migrations/create_background_job_logs_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_background_job_logs_table.php'),
        ], 'background-jobs-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileBackgroundJobRunsCommand::class,
                FailStaleBackgroundJobsCommand::class,
                ImportFileLogsCommand::class,
                PruneBackgroundJobLogsCommand::class,
            ]);
        }

        $this->registerListeners();
        $this->registerLogFlushing();
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
     * The log entries wait in a buffer. Write them when a queued job ends, so
     * that a long-running worker does not keep them, and when the process
     * stops. The shutdown function also runs when the worker exits after a
     * timeout or a fatal error, which is when the last entries matter most.
     */
    protected function registerLogFlushing(): void
    {
        $flush = function () {
            if ($this->app->resolved(BackgroundJobLogs::class)) {
                $this->app->make(BackgroundJobLogs::class)->flushAll();
            }
        };

        Event::listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class, JobTimedOut::class], $flush);

        $this->app->terminating($flush);

        if (static::$shutdownFlushRegistered) {
            return;
        }

        static::$shutdownFlushRegistered = true;

        // Registered one time for each process, and it resolves the current
        // container. A test suite boots many applications in one process.
        register_shutdown_function(static function () {
            try {
                $app = Container::getInstance();

                if ($app->resolved(BackgroundJobLogs::class)) {
                    $app->make(BackgroundJobLogs::class)->flushAll();
                }
            } catch (\Throwable) {
                // The process is ending. There is nothing more to do.
            }
        });
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

<?php

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobFailed;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobSucceeded;

return [

    /*
     * The model that stores the background jobs. You can extend the package
     * model to add relations, casts or a URL. Set your model here when you do.
     */
    'model' => BackgroundJob::class,

    /*
     * The model of the user who starts a job. Set null to use the auth user
     * model (config('auth.providers.users.model')).
     */
    'user_model' => null,

    /*
     * The enums that list the job types. Each enum must be a backed enum that
     * implements the BackgroundJobType contract. A package can also add its
     * own enum with BackgroundJobs::registerTypes().
     */
    'types' => [
        // App\Enums\BackgroundJobType::class,
    ],

    /*
     * A job that is waiting or processing and has had no update for this
     * number of hours is stale. The "background-jobs:fail-stale" command then
     * fails it. A status change, a log entry, a meta change and progress each
     * update the job.
     */
    'stale_after_hours' => 6,

    /*
     * The log of each job. The "database" store keeps the logs in a table, so
     * that all servers can read them. Use the "file" store only when one server
     * runs the web requests and the queue workers. You can also give the class
     * of your own store, which implements the BackgroundJobLogStore contract.
     */
    'logs' => [

        'store' => 'database',

        // The connection and the table of the database store. Set a separate
        // connection when jobs log in transactions that can roll back.
        'connection' => null,
        'table' => 'background_job_logs',

        // The directory of the file store, and of the files to import with
        // the "background-jobs:import-file-logs" command.
        'path' => storage_path('logs/background-jobs'),

        // The package keeps log entries in a buffer and writes them in
        // batches: when the buffer has this number of entries, when the oldest
        // entry is this number of seconds old, at an error, and when the
        // queued job ends.
        'buffer_size' => 100,
        'flush_interval' => 2,

        // The maximum number of entries that a job page shows.
        'display_limit' => 5000,

    ],

    /*
     * How long to keep settled jobs and their logs, in days. Set a value to
     * null to keep those jobs or logs forever. Running jobs are never pruned.
     *
     * "days" applies to finished jobs and "failed_days" to failed jobs.
     * Schedule "model:prune" for your model to delete the old jobs. The
     * children, the logs and the temporary files of a job go with it.
     *
     * "logs" deletes only the logs, and keeps the jobs. Use it to keep the
     * history of the jobs longer than their logs. Schedule the
     * "background-jobs:prune-logs" command for it.
     */
    'retention' => [

        'days' => null,
        'failed_days' => null,

        'logs' => [
            'days' => null,
            'failed_days' => null,
        ],

    ],

    /*
     * The owner of a job gets a notification when the job is finished or
     * failed. A run sends one notification for all of its children.
     *
     * These are the defaults. A job type can choose its own notifications
     * with the ChoosesBackgroundJobNotifications contract, and the app can
     * choose with BackgroundJobs::chooseNotificationUsing(). Set a class to
     * null to send nothing for that outcome.
     */
    'notifications' => [

        'enabled' => true,

        // The queue for the notifications. Set null to use the default queue.
        'queue' => null,

        // The channels for the notifications.
        'channels' => ['mail'],

        'succeeded' => BackgroundJobSucceeded::class,
        'failed' => BackgroundJobFailed::class,
        'run_completed' => BackgroundJobRunCompleted::class,

    ],

    /*
     * Each status change broadcasts, so that a page can refresh itself.
     */
    'broadcasting' => [

        'enabled' => true,

        // Set false to authorize the broadcast channels in your own
        // routes/channels.php file.
        'register_channels' => true,

    ],

    /*
     * When a job is finished or failed, the package deletes the temporary file
     * of the job. See BackgroundJob::setTemporaryFile().
     */
    'remove_temporary_files' => true,

    'temporary_files' => [

        // TemporaryFile::localPath() downloads a file from a remote disk to
        // this directory. Set null to use the temporary directory of the
        // system. Large imports can need more space than that.
        'local_directory' => null,

    ],

];

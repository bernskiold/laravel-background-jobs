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
     * A job that is not finished after this number of hours is stale. The
     * package then stops to show it as "still running", and a wait for it
     * stops.
     */
    'stale_after_hours' => 6,

    /*
     * Each job writes its log to a file of its own in this directory.
     */
    'logs' => [
        'path' => storage_path('logs/background-jobs'),
    ],

    /*
     * The owner of a job gets a notification when the job is finished or
     * failed. A run sends one notification for all of its children.
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
     * When a job is finished or failed, the package deletes the local file in
     * the "file_path" item of its meta.
     */
    'remove_temporary_files' => true,

];

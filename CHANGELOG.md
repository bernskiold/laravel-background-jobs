# Changelog

All notable changes to `laravel-background-jobs` will be documented in this file.

## 1.0.1 - 2026-10-27

- The `background-jobs:import-file-logs` command reads each log file line by line and writes 500 entries at a time. Before, a large log file stopped the command with an "Allowed memory size exhausted" error.
- If a write fails, the import command deletes the entries that it wrote for that job. A second run then imports the file again.

## 1.0.0 - 2026-10-27

- Initial release.
- A `BackgroundJob` model with a status, an owner, a log, progress and a meta field. You can extend the model.
- Extensible job types: backed enums that implement the `BackgroundJobType` contract, from the config or from `BackgroundJobs::registerTypes()`.
- Bulk runs with `BackgroundJobRun`. A run reports one outcome and sends one notification.
- A roll-up that counts each child one time only, and lets only one worker settle a run.
- Notifications for finished jobs, failed jobs and settled runs. A job type can choose its own notifications with `ChoosesBackgroundJobNotifications`, and the app can choose with `BackgroundJobs::chooseNotificationUsing()`.
- The `BackgroundJobChangedStatus` event. It broadcasts as `background-job.status-changed` on private channels.
- The `AwaitBackgroundJobs` job, which keeps a chain open until jobs are settled.
- The `HasBackgroundJobs` trait for the models that jobs run for.
- A headless design: no views, routes or front-end code. Query scopes, abilities and broadcast events give a user interface what it needs.
- The `background-jobs:reconcile-runs` command, which settles runs that cannot settle on their own.
- Log stores. The `database` store is the default, so that all servers can read the logs. The `file` store and your own stores are also possible. The logger writes in batches.
- The `background-jobs:import-file-logs` command, which copies log files into the configured store.
- Temporary files on a filesystem disk, with `setTemporaryFile()`, `temporaryFile()` and `TemporaryFile::localPath()`.
- The `TrackBackgroundJob` job middleware and the `InteractsWithBackgroundJob` trait, which keep the status in step with the queued job.
- The `background-jobs:fail-stale` command, which fails jobs whose worker stopped and seals runs that were never sealed. A log entry, a meta change and progress each count as an update.
- Progress for standalone jobs, with `setProgressTotal()` and `advanceProgress()`.
- Retention with `model:prune`, configured in days for finished and for failed jobs.
- A `notify: false` option on `generateFor()` and `BackgroundJobRun::start()`. The job keeps its owner but sends no notification. A child gets the setting of its run.
- A separate log retention, with the `background-jobs:prune-logs` command, which deletes old logs and keeps the jobs.

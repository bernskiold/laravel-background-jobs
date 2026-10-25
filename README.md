# Track queued work as background jobs

[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-background-jobs/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bernskiold/laravel-background-jobs/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/bernskiold/laravel-background-jobs/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/bernskiold/laravel-background-jobs/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

A queued job runs out of sight. The person who started it does not know if it is still running, if it is finished, or why it failed. This package records each unit of work as a background job in the database. The job has a type, a status, an owner, a log file and a meta field.

```php
$job = BackgroundJob::generateFor($report, JobType::ImportReports, ['file' => $path]);

ImportReport::dispatch($job);

// In the queued job:
$job->start();
$job->logChannel()->info('Read 1 200 rows.');
$job->finish(); // or $job->fail('The file is empty.');
```

When the job is finished or failed, the owner gets an email.

## Features

- **Status and owner.** Each job has a status (waiting, processing, finished, failed or completed with errors) and an owner.
- **Bulk runs.** A run puts many jobs under one parent job. The run reports one outcome and sends one email, and not one email for each item.
- **Safe for parallel workers.** The run counts each child one time only, and only one worker can settle the run.
- **Extensible job types.** Each domain or package can have its own enum of job types.
- **A log for each job, on all servers.** The logs are in the database by default, so each server can read them. Entries are written in batches.
- **Ready for many servers.** Temporary files are on a filesystem disk, and the logs are in the database. Any worker can process a job, and any web server can show it.
- **Status that stays true.** A job middleware keeps the status in step with the queued job, and a command fails the jobs whose worker stopped.
- **Progress.** Runs count their children. A standalone job can report its own progress.
- **Retention.** Old jobs, their logs and their files are pruned with `model:prune`.
- **Notifications.** The owner gets an email when a job or a run is finished or failed.
- **Live updates.** Each status change broadcasts. A page can refresh when a job changes.
- **Headless Livewire components.** Base components give the behavior of the job pages. You supply the views.
- **A safety net.** A command finds runs that cannot settle on their own, and settles them.

## Installation

Install the package with Composer:

```bash
composer require bernskiold/laravel-background-jobs
```

Publish and run the migrations. They make the `background_jobs` table and the `background_job_logs` table:

```bash
php artisan vendor:publish --tag=background-jobs-migrations
php artisan migrate
```

You can publish the config file:

```bash
php artisan vendor:publish --tag=background-jobs-config
```

## Job types

A job type is a case of a backed enum. The enum must implement the `BackgroundJobType` contract:

```php
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;

enum JobType: string implements BackgroundJobType
{
    case ImportReports = 'import_reports';
    case ExportReports = 'export_reports';

    public function label(): string
    {
        return match ($this) {
            self::ImportReports => __('Import Reports'),
            self::ExportReports => __('Export Reports'),
        };
    }
}
```

Add the enum to the config:

```php
// config/background-jobs.php
'types' => [
    App\Enums\JobType::class,
],
```

A package can add its own enum in its service provider:

```php
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;

BackgroundJobs::registerTypes(ExportJobType::class);
```

The database stores the backing value. The values must be unique across all the enums. When you remove a case, the old jobs still load. Their `type` is then `null`, and `typeLabel()` shows the stored value.

## Usage

### Make a job

Use `generateFor()` to make a job for a model. The model can be `null`:

```php
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;

$job = BackgroundJob::generateFor(
    model: $report,
    type: JobType::ImportReports,
    meta: ['file_path' => $path],
);
```

The owner is the user who is logged in. You can give a different owner with `userId:`. A job without an owner does not send an email. Use `withoutOwner: true` for system jobs.

### Change the status

```php
$job->start();                  // waiting → processing
$job->finish();                 // → finished
$job->fail('The file is empty.'); // → failed, with a reason
```

Each status change fires the `BackgroundJobChangedStatus` event.

### Write to the log

```php
$job->logChannel()->info('Read 1 200 rows.');
$job->logChannel()->error('Row 1 201 is broken.', ['row' => 1201]);

$job->logEntries();                 // the latest entries, as LogEntry objects
$job->getLogContents();             // the latest entries, as text
$job->getLogContentsForLevel('error');
```

The `logs.store` config decides where the logs are kept:

| Store | Use |
| --- | --- |
| `database` (default) | The `background_job_logs` table. All servers can read the logs. A log is deleted with its job. |
| `file` | One local file for each job. Use it only when one server runs the web requests and the workers. |
| A class name | Your own store, which implements the `BackgroundJobLogStore` contract. |

The logger keeps the entries in a buffer and writes them in batches. It writes the buffer when it is full (`logs.buffer_size`), when the oldest entry is older than `logs.flush_interval` seconds, at once for an error, when the job settles, when a queued job ends, and when the process stops.

A job that logs in a database transaction loses the entries when the transaction rolls back. Set `logs.connection` to a separate connection to prevent this.

#### Move from log files to the database

The versions before the database store kept each log in a file. Run this command one time on each server that has log files:

```bash
php artisan background-jobs:import-file-logs --delete
```

### Store data in the meta

```php
$job->updateMetaItem('rows', 1200);
$job->incrementMetaItem('imported');
$job->appendMetaArrayItem('warnings', 'Row 12 has no date.');
```

`incrementMetaItem()` and `appendMetaArrayItem()` lock the row. Parallel jobs can use them on the same job.

### Temporary files

A job often uses a file, for example an upload that it imports. Record the file on a filesystem disk:

```php
$path = $request->file('import')->store('imports', 's3');

$job->setTemporaryFile($path, disk: 's3');
```

The package deletes the file when the job is finished or failed. To read the file in the queued job, use `localPath()`. It downloads a file from a remote disk to the local temporary directory one time:

```php
$file = $job->temporaryFile();

$rows = SimpleExcelReader::create($file->localPath())->getRows();

$file->cleanUp(); // delete the local copy
```

Use a disk that all servers can read, for example S3. Then any worker can process the job. A `file_path` item in the meta, from earlier versions, still works, but only on the server that has the file.

### Progress

A run counts its children. A standalone job can report its own progress:

```php
$job->setProgressTotal(count($rows));

foreach ($rows as $row) {
    // ...
    $job->advanceProgress();
}

$job->progressPercentage(); // 0 to 100
```

`advanceProgress()` adds the number in the database, so parallel workers do not lose an update.

### Bulk runs

A run puts the jobs of a bulk operation under one parent job. Always use these three steps:

```php
use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;

$run = BackgroundJobRun::start(JobType::ImportReports, meta: ['item_label' => 'report']);

foreach ($files as $file) {
    $child = $run->addChild(null, ['label' => $file->name]);

    ImportReport::dispatch($child, $file);
}

$run->seal();
```

The `seal()` step is necessary. The run cannot settle before `seal()` declares the number of children. Thus a child that finishes early on a sync queue cannot settle the run before the other children exist.

When all the children are settled, the run gets its final status:

| Children | Status of the run |
| --- | --- |
| All finished | Finished |
| Some failed | Completed with errors |
| All failed | Failed |

Use `noteSkipped()` for an item that you do not dispatch on purpose. A skipped item is not a failure. The email shows skipped items separately.

The `item_label` meta item gives the noun for the email, for example "All 40 reports completed successfully".

### Queued jobs

Add the `TrackBackgroundJob` middleware and the `InteractsWithBackgroundJob` trait to a queued job that does the work of a background job. The job must have a `backgroundJob` property:

```php
use Bernskiold\LaravelBackgroundJobs\Concerns\InteractsWithBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Jobs\Middleware\TrackBackgroundJob;

class ImportReport implements ShouldQueue
{
    use InteractsWithBackgroundJob, Queueable;

    public function __construct(public BackgroundJob $backgroundJob) {}

    public function middleware(): array
    {
        return [TrackBackgroundJob::andFinish()];
    }

    public function handle(): void
    {
        // Only the work. The middleware sets the status.
    }
}
```

The middleware:

- does not run the queued job when the background job has failed. In a batch, one failure thus stops the jobs after it,
- sets the background job to processing,
- fails the background job with the message of an exception, and gives the exception to the queue,
- finishes the background job when you use `andFinish()`. Do not use it when a batch or a later job finishes the background job.

The trait fails the background job in `failed()`. The queue calls `failed()` also after a timeout, which the middleware cannot see. If your job has its own `failed()` method, call `$this->failBackgroundJob($exception)` from it.

For a job that the queue must retry, use `new TrackBackgroundJob(failOnException: false)`. Otherwise the first exception fails the background job and the retry does not run.

### Wait for jobs in a chain

`AwaitBackgroundJobs` keeps a chain open until the jobs that you give it are settled:

```php
Bus::chain([
    new StartImports($study),
    new AwaitBackgroundJobs($jobIds),
    new PublishStudy($study),
])->dispatch();
```

The job polls with a longer interval each time. It stops after the stale window.

### Jobs of a model

Use the `HasBackgroundJobs` trait on a model that jobs run for:

```php
use Bernskiold\LaravelBackgroundJobs\Concerns\HasBackgroundJobs;

class Report extends Model
{
    use HasBackgroundJobs;
}

$report->backgroundJobs;
$report->hasUnfinishedBackgroundJob(JobType::ImportReports);
Report::idsWithUnfinishedBackgroundJob(JobType::ImportReports);
```

### Query scopes

```php
BackgroundJob::query()->ofType(JobType::ImportReports);
BackgroundJob::query()->forModel($report);
BackgroundJob::query()->roots();             // no children of runs
BackgroundJob::query()->forParents($run);    // the children of a run
BackgroundJob::query()->mine();              // the jobs of the logged-in user
BackgroundJob::query()->notFinished();       // waiting or processing
BackgroundJob::query()->startedRecently();   // in the stale window
```

## Your own model

Extend the package model to add relations, casts or a URL. Then set your model in the config:

```php
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob as BaseBackgroundJob;

class BackgroundJob extends BaseBackgroundJob
{
    public function url(): string
    {
        return route('background-jobs.show', $this);
    }
}
```

```php
// config/background-jobs.php
'model' => App\Models\BackgroundJob::class,
```

The package registers its status event in `boot()`. You can use `booted()` in your model.

If you do not extend the model, give the URL of the job page with a resolver:

```php
BackgroundJobs::resolveUrlUsing(fn (BackgroundJob $job) => route('background-jobs.show', $job));
```

## Authorization

The package does not have a policy. Your application decides who can see a job. The package uses these abilities:

| Ability | Use |
| --- | --- |
| `viewAny` | See the list of jobs. Listen to the shared channel. |
| `viewAllJobs` | See the jobs of all users, and not only your own jobs. |
| `view` | See a job. Listen to the channel of a job or a run. |
| `update` | Mark a job as failed. |

Override `viewAbility()` and `updateAbility()` on your model to use different abilities for some jobs.

The owner only gets an email when the owner can still `view` the job. When your application has no policy and no gate for the ability, the package does not check.

## Notifications

The owner gets one of these notifications:

- `BackgroundJobSucceeded` when a job is finished.
- `BackgroundJobFailed` when a job failed.
- `BackgroundJobRunCompleted` when a run is settled.

The children of a run do not send notifications. Set the queue, the channels and the classes in the config:

```php
'notifications' => [
    'enabled' => true,
    'queue' => 'notifications',
    'channels' => ['mail'],
    'succeeded' => BackgroundJobSucceeded::class,
    'failed' => BackgroundJobFailed::class,
    'run_completed' => BackgroundJobRunCompleted::class,
],
```

## Broadcasting

Each status change broadcasts as `background-job.status-changed` on private channels:

| Channel | Events |
| --- | --- |
| `background-job-status.{id}` | The changes of one job. |
| `background-job-run.{id}` | The changes of the children of a run. |
| `background-job-changes` | The changes of standalone jobs and runs. |

The children of a run do not broadcast on the shared channel. A large run can have hundreds of children.

The name has no namespace. In Laravel Echo, put a dot before the name:

```js
Echo.private('background-job-changes').listen('.background-job.status-changed', refresh);
```

The package authorizes the channels with the abilities above. To authorize them in your own `routes/channels.php` file, set `broadcasting.register_channels` to `false`.

## Livewire components

The package has no views. It gives abstract Livewire components with the behavior. You extend them and supply the views:

| Component | Use |
| --- | --- |
| `BackgroundJobList` | The list of jobs, with a filter for the children of runs. |
| `BackgroundJobDetail` | The page of one job, with a log filter and "mark as failed". |
| `BackgroundJobRunChildren` | The jobs of a run, with a status filter. |
| `ModelBackgroundJobs` | The jobs of one model. |

```php
use Bernskiold\LaravelBackgroundJobs\Livewire\BackgroundJobList;

class BackgroundJobs extends BackgroundJobList
{
    public function render(): View
    {
        return view('livewire.background-jobs.index');
    }
}
```

The components refresh when a job changes. Override `query()`, `columns()` or the ability methods to change their behavior.

## Stale jobs and the safety net

When a worker stops during a job, for example because it runs out of memory, nothing changes the status of the job. Schedule two commands:

```php
Schedule::command('background-jobs:fail-stale')->hourly()->withoutOverlapping();
Schedule::command('background-jobs:reconcile-runs')->everyFifteenMinutes()->withoutOverlapping();
```

`background-jobs:fail-stale` fails each job that is waiting or processing and has had no update for `stale_after_hours`. A status change, a log entry, a meta change and progress each update the job. Thus a long job that logs or reports progress stays alive. The command also seals a stale run that was never sealed, so that the run can settle.

`background-jobs:reconcile-runs` counts the children of unsettled runs again, and settles the runs that are complete. It does not change a run with children that still run. It warns about a run that runs for more than 24 hours.

## Retention

Set the retention in days, and schedule `model:prune` for your model:

```php
// config/background-jobs.php
'retention' => [
    'days' => 90,          // finished jobs
    'failed_days' => 365,  // failed jobs
],
```

```php
Schedule::command('model:prune', ['--model' => [App\Models\BackgroundJob::class]])->daily();
```

The command deletes settled jobs that are older than the retention, with their children, their logs and their temporary files. A value of `null` keeps those jobs forever. Running jobs are never deleted.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Erik Bernskiöld](https://bernskiold.com)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see the [License File](LICENSE.md) for more information.

<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\BackgroundJobLogs;
use Bernskiold\LaravelBackgroundJobs\Logging\DatabaseLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\FileLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\LogEntry;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    File::deleteDirectory(BackgroundJobs::logPath());
});

describe('the database store', function () {
    it('is the default store', function () {
        expect(app(BackgroundJobLogStore::class))->toBeInstanceOf(DatabaseLogStore::class);
    });

    it('keeps the entries in the table', function () {
        $job = BackgroundJob::factory()->create();

        $job->logChannel()->info('Read 10 rows.', ['file' => 'q3.csv']);
        $job->flushLog();

        expect(DB::table('background_job_logs')->where('background_job_id', $job->id)->first())
            ->level->toBe('info')
            ->message->toBe('Read 10 rows.')
            ->context->toBe('{"file":"q3.csv"}');
    });

    it('reads the latest entries, oldest first', function () {
        $job = BackgroundJob::factory()->create();

        foreach (range(1, 5) as $row) {
            $job->logChannel()->info("Row {$row}");
        }

        $messages = array_map(fn (LogEntry $entry) => $entry->message, $job->logEntries(limit: 2));

        expect($messages)->toBe(['Row 4', 'Row 5']);
    });

    it('filters the entries by level', function () {
        $job = BackgroundJob::factory()->create();
        $job->logChannel()->info('Read 10 rows.');
        $job->logChannel()->error('Row 11 is broken.');

        expect($job->getLogContentsForLevel('error'))->toContain('ERROR: Row 11 is broken.')
            ->not->toContain('Read 10 rows.');
    });

    it('normalizes an exception in the context', function () {
        $job = BackgroundJob::factory()->create();

        $job->logChannel()->error('Failed.', ['exception' => new RuntimeException('The file is empty.')]);

        expect($job->logEntries()[0]->context['exception']['message'])->toBe('The file is empty.');
    });

    it('deletes the log with the job', function () {
        $job = BackgroundJob::factory()->create();
        $job->logChannel()->info('Read 10 rows.');
        $job->flushLog();

        $job->delete();

        expect(DB::table('background_job_logs')->count())->toBe(0);
    });
});

describe('the buffer', function () {
    it('keeps entries until the buffer is full', function () {
        config(['background-jobs.logs.buffer_size' => 3, 'background-jobs.logs.flush_interval' => 60]);

        $job = BackgroundJob::factory()->create();

        $job->logChannel()->info('One');
        $job->logChannel()->info('Two');

        expect(DB::table('background_job_logs')->count())->toBe(0);

        $job->logChannel()->info('Three');

        expect(DB::table('background_job_logs')->count())->toBe(3);
    });

    it('writes an error at once', function () {
        config(['background-jobs.logs.buffer_size' => 100, 'background-jobs.logs.flush_interval' => 60]);

        $job = BackgroundJob::factory()->create();

        $job->logChannel()->info('One');
        $job->logChannel()->error('Broken');

        expect(DB::table('background_job_logs')->count())->toBe(2);
    });

    it('writes all buffers when a queued job ends', function () {
        config(['background-jobs.logs.buffer_size' => 100, 'background-jobs.logs.flush_interval' => 60]);

        $job = BackgroundJob::factory()->create();
        $job->logChannel()->info('One');

        event(new JobProcessed('sync', Mockery::mock(Job::class)));

        expect(DB::table('background_job_logs')->count())->toBe(1);
    });

    it('writes the buffer before the job settles', function () {
        config(['background-jobs.logs.buffer_size' => 100, 'background-jobs.logs.flush_interval' => 60]);

        $job = BackgroundJob::factory()->create();
        $job->logChannel()->info('Done.');

        $job->finish();

        expect(DB::table('background_job_logs')->count())->toBe(1);
    });

    it('updates the job when it writes, which shows that the job is alive', function () {
        $job = BackgroundJob::factory()->processing()->create(['updated_at' => now()->subDay()]);

        $job->logChannel()->info('Still going.');
        $job->flushLog();

        expect($job->refresh()->updated_at->isToday())->toBeTrue();
    });
});

describe('the file store', function () {
    beforeEach(function () {
        config(['background-jobs.logs.store' => 'file']);
        app()->forgetInstance(BackgroundJobLogStore::class);
        app()->forgetInstance(BackgroundJobLogs::class);
    });

    it('keeps each log in a file', function () {
        $job = BackgroundJob::factory()->create();

        $job->logChannel()->info('Read 10 rows.');
        $job->logChannel()->error('Row 11 is broken.');

        expect(app(BackgroundJobLogStore::class))->toBeInstanceOf(FileLogStore::class)
            ->and(File::exists(BackgroundJobs::logPath()."/{$job->id}.log"))->toBeTrue()
            ->and($job->hasLog())->toBeTrue()
            ->and($job->getLogContentsForLevel('error'))->toContain('Row 11 is broken.')
            ->and($job->getLogContentsForLevel('error'))->not->toContain('Read 10 rows.');
    });

    it('reads the files of earlier versions', function () {
        $job = BackgroundJob::factory()->create();

        File::ensureDirectoryExists(BackgroundJobs::logPath());
        File::put(BackgroundJobs::logPath()."/{$job->id}.log", implode("\n", [
            '[2026-10-20T10:00:00.000000+00:00] background-job.INFO: Started. [] []',
            '[2026-10-20T10:00:05.000000+00:00] background-job.ERROR: It broke. [] []',
            '#0 /app/Import.php(12): run()',
            '',
        ]));

        $entries = $job->logEntries();

        expect($entries)->toHaveCount(2)
            ->and($entries[1]->level)->toBe('error')
            ->and($entries[1]->message)->toContain("It broke. [] []\n#0 /app/Import.php(12): run()")
            ->and($entries[1]->loggedAt->format('Y-m-d H:i:s'))->toBe('2026-10-20 10:00:05');
    });
});

describe('the import command', function () {
    it('copies the log files into the database', function () {
        $job = BackgroundJob::factory()->create();

        File::ensureDirectoryExists(BackgroundJobs::logPath());
        File::put(BackgroundJobs::logPath()."/{$job->id}.log", "[2026-10-20T10:00:00.000000+00:00] background-job.INFO: Started. [] []\n");
        File::put(BackgroundJobs::logPath().'/999999.log', "[2026-10-20T10:00:00.000000+00:00] background-job.INFO: Orphan. [] []\n");

        $this->artisan('background-jobs:import-file-logs', ['--delete' => true])
            ->expectsOutputToContain('Imported 1 log file(s). Skipped 1.')
            ->assertSuccessful();

        expect($job->getLogContents())->toContain('INFO: Started.')
            ->and(File::exists(BackgroundJobs::logPath()."/{$job->id}.log"))->toBeFalse();
    });

    it('does not import a log twice', function () {
        $job = BackgroundJob::factory()->create();
        $job->logChannel()->info('Already in the database.');
        $job->flushLog();

        File::ensureDirectoryExists(BackgroundJobs::logPath());
        File::put(BackgroundJobs::logPath()."/{$job->id}.log", "[2026-10-20T10:00:00.000000+00:00] background-job.INFO: Started. [] []\n");

        $this->artisan('background-jobs:import-file-logs')->assertSuccessful();

        expect($job->logEntries())->toHaveCount(1);
    });

    it('refuses to import into the file store', function () {
        config(['background-jobs.logs.store' => 'file']);
        app()->forgetInstance(BackgroundJobLogStore::class);
        app()->forgetInstance(BackgroundJobLogs::class);

        $this->artisan('background-jobs:import-file-logs')->assertFailed();
    });
});

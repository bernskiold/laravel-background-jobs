<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\BackgroundJobLogs;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

describe('progress', function () {
    it('reports the progress of a standalone job', function () {
        $job = BackgroundJob::factory()->processing()->create();

        expect($job->hasProgress())->toBeFalse();

        $job->setProgressTotal(200)->advanceProgress(50)->advanceProgress();

        expect($job->progress_processed)->toBe(51)
            ->and($job->hasProgress())->toBeTrue()
            ->and($job->progressPercentage())->toBe(25)
            ->and($job->refresh()->progress_processed)->toBe(51);
    });

    it('adds the progress in the database', function () {
        $job = BackgroundJob::factory()->create(['progress_total' => 10]);
        $sameJobInAnotherWorker = BackgroundJob::query()->find($job->id);

        $job->advanceProgress(3);
        $sameJobInAnotherWorker->advanceProgress(4);

        expect($job->refresh()->progress_processed)->toBe(7);
    });

    it('updates the job, which shows that the job is alive', function () {
        $job = BackgroundJob::factory()->create(['updated_at' => now()->subDay()]);

        $job->advanceProgress();

        expect($job->refresh()->updated_at->isToday())->toBeTrue();
    });

    it('never reports more than 100 percent', function () {
        $job = BackgroundJob::factory()->create(['progress_total' => 2, 'progress_processed' => 5]);

        expect($job->progressPercentage())->toBe(100);
    });
});

describe('pruning', function () {
    it('keeps all jobs when there is no retention', function () {
        BackgroundJob::factory()->finished()->create(['created_at' => now()->subYears(5)]);

        $this->artisan('model:prune', ['--model' => BackgroundJob::class])->assertSuccessful();

        expect(BackgroundJob::query()->count())->toBe(1);
    });

    it('deletes old settled jobs with their children and logs', function () {
        config(['background-jobs.retention.days' => 30, 'background-jobs.retention.failed_days' => 90]);

        $oldRun = BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(31)]);
        $child = BackgroundJob::factory()->childOf($oldRun)->finished()->create(['created_at' => now()->subDays(31)]);
        $child->logChannel()->info('Child log.');
        $child->flushLog();

        $recent = BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(29)]);
        $oldFailure = BackgroundJob::factory()->failed()->create(['created_at' => now()->subDays(31)]);
        $ancientFailure = BackgroundJob::factory()->failed()->create(['created_at' => now()->subDays(91)]);
        $stillRunning = BackgroundJob::factory()->processing()->create(['created_at' => now()->subDays(31)]);

        $this->artisan('model:prune', ['--model' => BackgroundJob::class])->assertSuccessful();

        expect(BackgroundJob::query()->pluck('id')->sort()->values()->all())
            ->toBe([$recent->id, $oldFailure->id, $stillRunning->id])
            ->and(DB::table('background_job_logs')->count())->toBe(0);
    });

    it('keeps failed jobs forever when failed_days is null', function () {
        config(['background-jobs.retention.days' => 30, 'background-jobs.retention.failed_days' => null]);

        $failure = BackgroundJob::factory()->failed()->create(['created_at' => now()->subYears(5)]);

        $this->artisan('model:prune', ['--model' => BackgroundJob::class])->assertSuccessful();

        expect(BackgroundJob::query()->pluck('id')->all())->toBe([$failure->id]);
    });

    it('deletes the log files of the file store', function () {
        config([
            'background-jobs.retention.days' => 30,
            'background-jobs.logs.store' => 'file',
        ]);
        app()->forgetInstance(BackgroundJobLogStore::class);
        app()->forgetInstance(BackgroundJobLogs::class);

        $job = BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(31)]);
        $job->logChannel()->info('Old log.');
        $job->flushLog();

        expect(File::exists(BackgroundJobs::logPath()."/{$job->id}.log"))->toBeTrue();

        $this->artisan('model:prune', ['--model' => BackgroundJob::class])->assertSuccessful();

        expect(File::exists(BackgroundJobs::logPath()."/{$job->id}.log"))->toBeFalse();
    });
});

describe('log retention', function () {
    beforeEach(function () {
        $this->logged = function (BackgroundJob $job, string $message = 'A line.'): BackgroundJob {
            $job->logChannel()->info($message);
            $job->flushLog();

            return $job;
        };
    });

    it('deletes nothing when there is no log retention', function () {
        ($this->logged)(BackgroundJob::factory()->finished()->create(['created_at' => now()->subYears(5)]));

        $this->artisan('background-jobs:prune-logs')
            ->expectsOutputToContain('There is no log retention.')
            ->assertSuccessful();

        expect(DB::table('background_job_logs')->count())->toBe(1);
    });

    it('deletes old logs and keeps the jobs', function () {
        config(['background-jobs.retention.logs.days' => 30, 'background-jobs.retention.logs.failed_days' => 90]);

        $oldFinished = ($this->logged)(BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(31)]));
        $recentFinished = ($this->logged)(BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(29)]));
        $oldFailed = ($this->logged)(BackgroundJob::factory()->failed()->create(['created_at' => now()->subDays(31)]));
        $ancientFailed = ($this->logged)(BackgroundJob::factory()->failed()->create(['created_at' => now()->subDays(91)]));
        $running = ($this->logged)(BackgroundJob::factory()->processing()->create(['created_at' => now()->subDays(31)]));

        $this->artisan('background-jobs:prune-logs')
            ->expectsOutputToContain('Deleted the logs of 2 job(s).')
            ->assertSuccessful();

        expect(BackgroundJob::query()->count())->toBe(5)
            ->and($oldFinished->hasLog())->toBeFalse()
            ->and($ancientFailed->hasLog())->toBeFalse()
            ->and($recentFinished->hasLog())->toBeTrue()
            ->and($oldFailed->hasLog())->toBeTrue()
            ->and($running->hasLog())->toBeTrue();
    });

    it('prunes the logs of children by their own status and age', function () {
        config(['background-jobs.retention.logs.days' => 30]);

        $run = BackgroundJob::factory()->processing()->create(['created_at' => now()->subDays(31)]);
        $child = ($this->logged)(BackgroundJob::factory()->childOf($run)->finished()->create(['created_at' => now()->subDays(31)]));

        $this->artisan('background-jobs:prune-logs')->assertSuccessful();

        expect($child->hasLog())->toBeFalse();
    });

    it('keeps the jobs longer than their logs', function () {
        config([
            'background-jobs.retention.days' => 365,
            'background-jobs.retention.logs.days' => 30,
        ]);

        $job = ($this->logged)(BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(60)]));

        $this->artisan('model:prune', ['--model' => BackgroundJob::class])->assertSuccessful();
        $this->artisan('background-jobs:prune-logs')->assertSuccessful();

        expect(BackgroundJob::query()->whereKey($job->id)->exists())->toBeTrue()
            ->and($job->hasLog())->toBeFalse();
    });

    it('deletes the log files of the file store', function () {
        config([
            'background-jobs.retention.logs.days' => 30,
            'background-jobs.logs.store' => 'file',
        ]);
        app()->forgetInstance(BackgroundJobLogStore::class);
        app()->forgetInstance(BackgroundJobLogs::class);

        $job = ($this->logged)(BackgroundJob::factory()->finished()->create(['created_at' => now()->subDays(31)]));

        $this->artisan('background-jobs:prune-logs')->assertSuccessful();

        expect(File::exists(BackgroundJobs::logPath()."/{$job->id}.log"))->toBeFalse();
    });
});

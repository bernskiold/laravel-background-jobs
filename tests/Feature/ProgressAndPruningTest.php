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

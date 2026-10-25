<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobFailed;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();

    $this->age = fn (BackgroundJob $job, int $hours) => BackgroundJobs::table()
        ->where('id', $job->id)
        ->update(['updated_at' => now()->subHours($hours)]);
});

it('fails a job that had no update in the stale window', function () {
    $job = BackgroundJob::factory()->for($this->owner)->processing()->create();
    ($this->age)($job, 7);

    $this->artisan('background-jobs:fail-stale')
        ->expectsOutputToContain('Failed 1 stale job(s) and sealed 0 run(s).')
        ->assertSuccessful();

    expect($job->refresh())
        ->status->toBe(BackgroundJobStatus::Failed)
        ->failure_reason->toBe('The job stopped without a final status. It had no update for 6 hours.');

    Notification::assertSentTo($this->owner, BackgroundJobFailed::class);
});

it('leaves a job alone that is still alive', function () {
    $job = BackgroundJob::factory()->processing()->create();
    ($this->age)($job, 7);

    $job->logChannel()->info('Still going.');
    $job->flushLog();

    $this->artisan('background-jobs:fail-stale')->assertSuccessful();

    expect($job->refresh()->status)->toBe(BackgroundJobStatus::Processing);
});

it('leaves settled jobs alone', function () {
    $job = BackgroundJob::factory()->finished()->create();
    ($this->age)($job, 7);

    $this->artisan('background-jobs:fail-stale')->assertSuccessful();

    expect($job->refresh()->status)->toBe(BackgroundJobStatus::Finished);
});

it('uses the hours that you give', function () {
    $job = BackgroundJob::factory()->processing()->create();
    ($this->age)($job, 3);

    $this->artisan('background-jobs:fail-stale', ['--hours' => 2])->assertSuccessful();

    expect($job->refresh()->status)->toBe(BackgroundJobStatus::Failed);
});

it('fails a stale child, which settles its run', function () {
    $run = BackgroundJobRun::start(JobType::ImportReports, userId: $this->owner->id);
    $done = $run->addChild(null);
    $stuck = $run->addChild(null);
    $run->seal();

    $done->finish();
    $stuck->start();
    ($this->age)($stuck, 7);
    ($this->age)($run->job, 7);

    $this->artisan('background-jobs:fail-stale')->assertSuccessful();

    expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::CompletedWithErrors);
    Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
});

it('seals a stale run that was never sealed', function () {
    $run = BackgroundJobRun::start(JobType::ImportReports, userId: $this->owner->id);
    $run->addChild(null)->finish();
    $run->addChild(null)->finish();
    ($this->age)($run->job, 7);

    $this->artisan('background-jobs:fail-stale')
        ->expectsOutputToContain('Failed 0 stale job(s) and sealed 1 run(s).')
        ->assertSuccessful();

    expect($run->job->refresh())
        ->status->toBe(BackgroundJobStatus::Finished)
        ->children_expected->toBe(2);
});

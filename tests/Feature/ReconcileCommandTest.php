<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();

    $this->startRun = fn () => BackgroundJobRun::start(
        type: JobType::ImportReports,
        meta: ['item_label' => 'report'],
        userId: $this->owner->id,
    );

    $this->age = fn (BackgroundJob $run, int $minutes) => BackgroundJobs::table()
        ->where('id', $run->id)
        ->update(['sealed_at' => now()->subMinutes($minutes)]);

    /**
     * Set a final status without Eloquent. Then no model event fires and the
     * roll-up does not run. A worker that stopped between the status write and
     * the event leaves this state.
     */
    $this->settleSilently = fn (Collection $children, BackgroundJobStatus $status, ?string $reason = null) => BackgroundJobs::table()
        ->whereIn('id', $children->pluck('id'))
        ->update([
            'status' => $status->value,
            'finished_at' => now(),
            'failure_reason' => $reason,
        ]);
});

it('settles a run when the roll-up did not occur', function () {
    $run = ($this->startRun)();
    $children = collect(range(1, 3))->map(fn () => $run->addChild(null));
    $run->seal();

    ($this->settleSilently)($children, BackgroundJobStatus::Finished);
    ($this->age)($run->job, 30);

    expect($run->job->refresh()->status->isRunning())->toBeTrue();

    $this->artisan('background-jobs:reconcile-runs')->assertSuccessful();

    expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::Finished)
        ->and($run->job->children_succeeded)->toBe(3);

    Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
});

it('counts the children again when the counters are wrong', function () {
    $run = ($this->startRun)();
    $children = collect(range(1, 4))->map(fn () => $run->addChild(null));
    $run->seal();

    ($this->settleSilently)($children->take(3), BackgroundJobStatus::Finished);
    ($this->settleSilently)($children->skip(3), BackgroundJobStatus::Failed, 'Broken');

    BackgroundJobs::table()->where('id', $run->job->id)->update([
        'children_succeeded' => 1,
        'children_failed' => 0,
    ]);

    ($this->age)($run->job, 30);

    $this->artisan('background-jobs:reconcile-runs')->assertSuccessful();

    $job = $run->job->refresh();

    expect($job->children_succeeded)->toBe(3)
        ->and($job->children_failed)->toBe(1)
        ->and($job->status)->toBe(BackgroundJobStatus::CompletedWithErrors);
});

it('does not change a run while children still run', function () {
    $run = ($this->startRun)();
    $children = collect(range(1, 3))->map(fn () => $run->addChild(null));
    $run->seal();

    ($this->settleSilently)($children->take(1), BackgroundJobStatus::Finished);
    ($this->age)($run->job, 30);

    $this->artisan('background-jobs:reconcile-runs')->assertSuccessful();

    expect($run->job->refresh()->status->isRunning())->toBeTrue();
    Notification::assertNothingSent();
});

it('warns about a run that runs for more than a day', function () {
    $run = ($this->startRun)();
    $run->addChild(null);
    $run->seal();

    ($this->age)($run->job, 60 * 25);

    $this->artisan('background-jobs:reconcile-runs')
        ->expectsOutputToContain("Run #{$run->job->id} has been running for more than 24 hours")
        ->assertSuccessful();
});

it('ignores runs that were sealed in the window', function () {
    $run = ($this->startRun)();
    $children = collect(range(1, 2))->map(fn () => $run->addChild(null));
    $run->seal();

    ($this->settleSilently)($children, BackgroundJobStatus::Finished);

    $this->artisan('background-jobs:reconcile-runs')->assertSuccessful();

    expect($run->job->refresh()->status->isRunning())->toBeTrue();
});

/**
 * A run that is not sealed is still being dispatched. To settle it is the bug
 * that the seal guard prevents.
 */
it('never changes a run that is not sealed', function () {
    $run = ($this->startRun)();
    $children = collect(range(1, 2))->map(fn () => $run->addChild(null));

    ($this->settleSilently)($children, BackgroundJobStatus::Finished);

    $this->artisan('background-jobs:reconcile-runs')->assertSuccessful();

    expect($run->job->refresh()->status->isRunning())->toBeTrue();
});

it('says when there is nothing to reconcile', function () {
    $this->artisan('background-jobs:reconcile-runs')
        ->expectsOutputToContain('No unsettled runs to reconcile.')
        ->assertSuccessful();
});

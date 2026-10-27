<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobSucceeded;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();
});

it('notifies the owner by default', function () {
    $job = BackgroundJob::generateFor(null, JobType::ImportReports, userId: $this->owner->id);

    expect($job->notify_owner)->toBeTrue()
        ->and($job->notifiesOwner())->toBeTrue();

    $job->finish();

    Notification::assertSentTo($this->owner, BackgroundJobSucceeded::class);
});

it('keeps the owner but sends nothing when notify is false', function (string $outcome) {
    $job = BackgroundJob::generateFor(null, JobType::ImportReports, userId: $this->owner->id, notify: false);

    $outcome === 'finish' ? $job->finish() : $job->fail('Broken');

    expect($job->refresh()->user_id)->toBe($this->owner->id)
        ->and($job->notifiesOwner())->toBeFalse();

    Notification::assertNothingSent();
})->with(['finish', 'fail']);

it('never notifies for a job without an owner', function () {
    $job = BackgroundJob::generateFor(null, JobType::ImportReports, withoutOwner: true);

    expect($job->notify_owner)->toBeTrue()
        ->and($job->notifiesOwner())->toBeFalse();
});

it('keeps the owner of a run but sends nothing when it settles', function () {
    $run = BackgroundJobRun::start(JobType::ImportReports, userId: $this->owner->id, notify: false);
    $children = collect(range(1, 3))->map(fn () => $run->addChild(null));
    $run->seal();

    $children->each->finish();

    expect($run->job->refresh()->user_id)->toBe($this->owner->id)
        ->and($children->every(fn (BackgroundJob $child) => $child->notify_owner === false && $child->user_id === $this->owner->id))->toBeTrue();

    Notification::assertNothingSent();
});

it('lets a child of a silent run inherit the setting', function () {
    $run = BackgroundJobRun::start(JobType::ImportReports, userId: $this->owner->id, notify: false);

    expect($run->addChild(null)->notify_owner)->toBeFalse();
});

it('comes before the hooks', function () {
    BackgroundJobs::chooseNotificationUsing(fn (BackgroundJob $job) => new BackgroundJobSucceeded($job));

    BackgroundJob::generateFor(null, JobType::ImportReports, userId: $this->owner->id, notify: false)->finish();

    Notification::assertNothingSent();
});

it('has a factory state', function () {
    $job = BackgroundJob::factory()->for($this->owner)->withoutNotifications()->create();

    $job->finish();

    expect($job->notify_owner)->toBeFalse();
    Notification::assertNothingSent();
});

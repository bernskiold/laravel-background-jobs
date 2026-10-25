<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobFailed;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobSucceeded;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Report;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();

    $this->startRun = fn (bool $withoutOwner = false) => BackgroundJobRun::start(
        type: JobType::ImportReports,
        meta: ['item_label' => 'report'],
        userId: $withoutOwner ? null : $this->owner->id,
        withoutOwner: $withoutOwner,
    );
});

it('sends one notification for a run of forty children', function () {
    $run = ($this->startRun)();

    $children = collect(range(1, 40))->map(fn () => $run->addChild(null));
    $run->seal();

    $children->each->finish();

    Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
    Notification::assertNotSentTo($this->owner, BackgroundJobSucceeded::class);
    Notification::assertNotSentTo($this->owner, BackgroundJobFailed::class);
});

it('sends one notification when some children fail', function () {
    $run = ($this->startRun)();

    $children = collect(range(1, 5))->map(fn () => $run->addChild(null));
    $run->seal();

    $children->take(3)->each->finish();
    $children->skip(3)->each(fn (BackgroundJob $child) => $child->fail('Broken'));

    Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
    Notification::assertNotSentTo($this->owner, BackgroundJobFailed::class);
});

it('notifies when the run had nothing to do', function () {
    $run = ($this->startRun)();
    $run->noteSkipped('No data available');
    $run->seal();

    Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
});

it('sends nothing for a run without an owner', function () {
    $run = ($this->startRun)(withoutOwner: true);

    $children = collect(range(1, 3))->map(fn () => $run->addChild(null));
    $run->seal();

    $children->each->finish();

    Notification::assertNothingSent();
});

it('never notifies for one child', function () {
    $run = ($this->startRun)();

    $child = $run->addChild(null);
    $run->addChild(null);
    $run->seal();

    $child->finish();

    Notification::assertNothingSent();
});

it('notifies when a standalone job succeeds', function () {
    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertSentTo($this->owner, BackgroundJobSucceeded::class);
});

it('notifies when a standalone job fails', function () {
    BackgroundJob::factory()->for($this->owner)->create()->fail('Broken');

    Notification::assertSentTo($this->owner, BackgroundJobFailed::class);
});

it('does not notify when a job starts', function () {
    BackgroundJob::factory()->for($this->owner)->create()->start();

    Notification::assertNothingSent();
});

it('does not notify when notifications are disabled', function () {
    config(['background-jobs.notifications.enabled' => false]);

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertNothingSent();
});

it('uses the notification classes from the config', function () {
    config(['background-jobs.notifications.succeeded' => BackgroundJobFailed::class]);

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertSentTo($this->owner, BackgroundJobFailed::class);
});

it('does not notify an owner who cannot see the job any more', function () {
    Gate::define('view', fn () => false);

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertNothingSent();
});

it('notifies an owner who can see the job', function () {
    Gate::define('view', fn ($user, BackgroundJob $job) => $job->isOwnedBy($user));

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertSentTo($this->owner, BackgroundJobSucceeded::class);
});

it('names the model and links to the job page', function () {
    BackgroundJobs::resolveUrlUsing(fn (BackgroundJob $job) => "https://example.com/jobs/{$job->id}");

    $report = Report::query()->create(['name' => 'Q3 Report']);
    $job = BackgroundJob::generateFor($report, JobType::ImportReports, userId: $this->owner->id);

    $mail = (new BackgroundJobSucceeded($job))->toMail($this->owner);

    expect($mail->subject)->toBe('[Import Reports] Background job completed successfully')
        ->and($mail->introLines)->toContain('The background job that you started for Q3 Report has been completed successfully.')
        ->and($mail->actionUrl)->toBe("https://example.com/jobs/{$job->id}");
});

it('leaves out the button when the job has no page', function () {
    $job = BackgroundJob::factory()->for($this->owner)->create();

    $mail = (new BackgroundJobFailed($job))->toMail($this->owner);

    expect($mail->actionUrl)->toBeNull()
        ->and($mail->introLines)->toContain('The background job that you started has failed.');
});

it('uses the queue from the config', function () {
    config(['background-jobs.notifications.queue' => 'notifications']);

    $notification = new BackgroundJobSucceeded(BackgroundJob::factory()->create());

    expect($notification->queue)->toBe('notifications');
});

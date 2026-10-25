<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();
});

$channelNames = fn (BackgroundJobChangedStatus $event): array => collect($event->broadcastOn())
    ->map(fn (PrivateChannel $channel) => $channel->name)
    ->all();

it('broadcasts a standalone job on the shared channel', function () use ($channelNames) {
    $event = new BackgroundJobChangedStatus(1, BackgroundJobStatus::Waiting, BackgroundJobStatus::Finished);

    expect($channelNames($event))->toBe([
        'private-background-job-status.1',
        'private-background-job-changes',
    ]);
});

/**
 * Each list refreshes on the shared channel. When the children stay off it, a
 * bulk run does not refresh each open list hundreds of times.
 */
it('keeps the children of a run off the shared channel', function () use ($channelNames) {
    $event = new BackgroundJobChangedStatus(7, BackgroundJobStatus::Waiting, BackgroundJobStatus::Finished, parentId: 3);

    expect($channelNames($event))->toBe([
        'private-background-job-status.7',
        'private-background-job-run.3',
    ]);
});

it('broadcasts with a name that has no namespace', function () {
    $event = new BackgroundJobChangedStatus(1, null, BackgroundJobStatus::Finished);

    expect($event->broadcastAs())->toBe('background-job.status-changed');
});

it('does not broadcast when broadcasting is disabled', function () {
    config(['background-jobs.broadcasting.enabled' => false]);

    expect((new BackgroundJobChangedStatus(1, null, BackgroundJobStatus::Finished))->broadcastWhen())->toBeFalse();
});

it('gives a child event the id of its parent', function () {
    Event::fake([BackgroundJobChangedStatus::class]);

    $run = BackgroundJobRun::start(type: JobType::ImportReports, userId: $this->owner->id);
    $child = $run->addChild(null);

    $child->finish();

    Event::assertDispatched(
        BackgroundJobChangedStatus::class,
        fn (BackgroundJobChangedStatus $event) => $event->backgroundJobId === $child->id
            && $event->parentId === $run->job->id
            && $event->from === BackgroundJobStatus::Waiting
            && $event->to === BackgroundJobStatus::Finished,
    );
});

it('sends no event when the status does not change', function () {
    $job = BackgroundJob::factory()->create();

    Event::fake([BackgroundJobChangedStatus::class]);

    $job->update(['failure_reason' => 'Nothing']);

    Event::assertNotDispatched(BackgroundJobChangedStatus::class);
});

/**
 * The lists care about the settle event of the run, so it must go to the
 * shared channel.
 */
it('broadcasts the run on the shared channel when it settles', function () use ($channelNames) {
    $run = BackgroundJobRun::start(type: JobType::ImportReports, userId: $this->owner->id);
    $child = $run->addChild(null);
    $run->seal();

    // A real listener records the events. Event::fake() stops the roll-up.
    $dispatched = collect();
    Event::listen(BackgroundJobChangedStatus::class, fn ($event) => $dispatched->push($event));

    $child->finish();

    $runEvent = $dispatched->firstWhere('backgroundJobId', $run->job->id);
    $childEvent = $dispatched->firstWhere('backgroundJobId', $child->id);

    expect($runEvent)->not->toBeNull()
        ->and($channelNames($runEvent))->toContain('private-background-job-changes')
        ->and($channelNames($childEvent))->not->toContain('private-background-job-changes');
});

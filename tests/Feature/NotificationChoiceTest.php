<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobOutcome;
use Bernskiold\LaravelBackgroundJobs\Listeners\NotifyOnFinish;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobFailed;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobSucceeded;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\NightlySyncFailed;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\QuietJobType;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    BackgroundJobs::registerTypes(QuietJobType::class);

    $this->owner = $this->user();
});

describe('a job type that chooses', function () {
    it('sends nothing when the type returns false', function () {
        BackgroundJob::factory()->for($this->owner)->ofType(QuietJobType::Cleanup)->create()->finish();

        Notification::assertNothingSent();
    });

    it('uses the config when the type returns null', function () {
        BackgroundJob::factory()->for($this->owner)->ofType(QuietJobType::Cleanup)->create()->fail('Broken');

        Notification::assertSentTo($this->owner, BackgroundJobFailed::class);
    });

    it('sends its own notification', function () {
        BackgroundJob::factory()->for($this->owner)->ofType(QuietJobType::NightlySync)->create()->fail('Broken');

        Notification::assertSentTo($this->owner, NightlySyncFailed::class);
    });

    it('chooses for a run too', function () {
        $run = BackgroundJobRun::start(QuietJobType::Cleanup, userId: $this->owner->id);
        $run->addChild(null)->finish();
        $run->seal();

        Notification::assertSentToTimes($this->owner, BackgroundJobRunCompleted::class, 1);
    });
});

describe('the app callback', function () {
    it('comes before the job type and the config', function () {
        BackgroundJobs::chooseNotificationUsing(
            fn (BackgroundJob $job, BackgroundJobOutcome $outcome) => $outcome === BackgroundJobOutcome::Succeeded
                ? new BackgroundJobFailed($job)
                : null
        );

        BackgroundJob::factory()->for($this->owner)->ofType(QuietJobType::Cleanup)->create()->finish();

        Notification::assertSentTo($this->owner, BackgroundJobFailed::class);
    });

    it('can turn off a notification', function () {
        BackgroundJobs::chooseNotificationUsing(fn () => false);

        BackgroundJob::factory()->for($this->owner)->ofType(JobType::ImportReports)->create()->finish();

        Notification::assertNothingSent();
    });

    it('lets the type and the config choose when it returns null', function () {
        BackgroundJobs::chooseNotificationUsing(fn () => null);

        BackgroundJob::factory()->for($this->owner)->ofType(JobType::ImportReports)->create()->finish();

        Notification::assertSentTo($this->owner, BackgroundJobSucceeded::class);
    });
});

it('sends nothing when the config class is null', function () {
    config(['background-jobs.notifications.succeeded' => null]);

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Notification::assertNothingSent();
});

it('does not queue the listener when nothing would be sent', function () {
    config(['background-jobs.notifications.succeeded' => null]);
    Queue::fake();

    BackgroundJob::factory()->for($this->owner)->create()->finish();

    Queue::assertNotPushed(CallQueuedListener::class,
        fn ($job) => $job->class === NotifyOnFinish::class);
});

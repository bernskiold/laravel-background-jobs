<?php

use Bernskiold\LaravelBackgroundJobs\Actions\RollUpBackgroundJobChild;
use Bernskiold\LaravelBackgroundJobs\Actions\SettleBackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Exceptions\SealedBackgroundJobRunException;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();

    $this->startRun = fn (array $meta = []) => BackgroundJobRun::start(
        type: JobType::ImportReports,
        meta: $meta,
        userId: $this->owner->id,
    );

    $this->addChildren = fn (BackgroundJobRun $run, int $count) => collect(range(1, $count))
        ->map(fn () => $run->addChild(null));
});

describe('settling', function () {
    it('finishes the run when all children succeed', function () {
        $run = ($this->startRun)();
        $children = ($this->addChildren)($run, 3);
        $run->seal();

        $children->each->finish();

        $job = $run->job->refresh();

        expect($job->status)->toBe(BackgroundJobStatus::Finished)
            ->and($job->children_succeeded)->toBe(3)
            ->and($job->children_failed)->toBe(0)
            ->and($job->finished_at)->not->toBeNull();
    });

    it('completes with errors when some children fail', function () {
        $run = ($this->startRun)();
        $children = ($this->addChildren)($run, 3);
        $run->seal();

        $children->take(2)->each->finish();
        $children->last()->fail('The import stopped.');

        $job = $run->job->refresh();

        expect($job->status)->toBe(BackgroundJobStatus::CompletedWithErrors)
            ->and($job->children_succeeded)->toBe(2)
            ->and($job->children_failed)->toBe(1);
    });

    it('fails the run when all children fail', function () {
        $run = ($this->startRun)();
        $children = ($this->addChildren)($run, 3);
        $run->seal();

        $children->each(fn (BackgroundJob $child) => $child->fail('No.'));

        expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::Failed);
    });

    it('starts the run as processing', function () {
        $run = ($this->startRun)();

        expect($run->job->status)->toBe(BackgroundJobStatus::Processing);
    });

    it('gives the children the type and the owner of the run', function () {
        $run = ($this->startRun)();
        $child = $run->addChild(null);

        expect($child->type)->toBe(JobType::ImportReports)
            ->and($child->user_id)->toBe($this->owner->id)
            ->and($child->parent_id)->toBe($run->job->id);
    });

    it('promotes a waiting run to processing when the first child starts', function () {
        $run = BackgroundJobRun::for(BackgroundJob::factory()->create());
        $child = $run->addChild(null);

        $child->start();

        expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::Processing);
    });
});

describe('the seal guard', function () {
    /**
     * On a sync queue, a child can finish before the other children exist.
     * Without the guard, the run settles at 1 of 1 and reports success for
     * work that never ran.
     */
    it('does not settle while children finish before the seal', function () {
        $run = ($this->startRun)();
        $children = ($this->addChildren)($run, 3);

        $children->each->finish();

        expect($run->job->refresh()->status->isRunning())->toBeTrue()
            ->and($run->job->children_succeeded)->toBe(3);

        $run->seal();

        expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::Finished);
    });

    it('settles a run without children immediately', function () {
        $run = ($this->startRun)();

        $job = $run->seal();

        expect($job->status)->toBe(BackgroundJobStatus::Finished)
            ->and($job->children_expected)->toBe(0);
    });

    it('refuses to add children after the seal', function () {
        $run = ($this->startRun)();
        $run->addChild(null);
        $run->seal();

        expect(fn () => $run->addChild(null))->toThrow(SealedBackgroundJobRunException::class);
    });

    it('keeps the expected count when you seal two times', function () {
        $run = ($this->startRun)();
        ($this->addChildren)($run, 2);

        $run->seal();
        $run->seal(99);

        expect($run->job->refresh()->children_expected)->toBe(2);
    });

    it('accepts an explicit expected count', function () {
        $run = ($this->startRun)();
        $child = $run->addChild(null);

        $run->seal(2);
        $child->finish();

        expect($run->job->refresh()->status->isRunning())->toBeTrue();
    });
});

describe('counting each child one time', function () {
    it('counts a child one time when it settles two times', function () {
        $run = ($this->startRun)();
        $child = $run->addChild(null);
        $run->addChild(null);
        $run->seal();

        $child->finish();
        $child->finish();
        app(RollUpBackgroundJobChild::class)->execute($child->refresh());

        expect($run->job->refresh()->children_succeeded)->toBe(1);
    });

    it('does not count a child again when it goes from finished to failed', function () {
        $run = ($this->startRun)();
        $child = $run->addChild(null);
        $run->addChild(null);
        $run->seal();

        $child->finish();
        $child->fail('Late failure');

        $job = $run->job->refresh();

        expect($job->children_succeeded)->toBe(1)
            ->and($job->children_failed)->toBe(0);
    });

    it('does not change a settled run when a late child reports', function () {
        $run = ($this->startRun)();
        $children = ($this->addChildren)($run, 2);
        $run->seal();

        $children->each->finish();

        BackgroundJob::factory()->childOf($run->job)->create()->fail('Too late');

        expect($run->job->refresh()->status)->toBe(BackgroundJobStatus::Finished);
    });

    /**
     * The claim is the base of the design. Parallel workers all try to settle
     * the run, and exactly one must win.
     */
    it('lets only one call settle the run', function () {
        $run = ($this->startRun)();
        $child = $run->addChild(null);
        $run->seal();

        $child->finish();

        expect(app(SettleBackgroundJobRun::class)->execute($run->job->id))->toBeFalse();
    });
});

it('ignores a job without a parent', function () {
    $job = BackgroundJob::factory()->create();

    app(RollUpBackgroundJobChild::class)->execute($job);

    expect($job->refresh()->rolled_up_at)->toBeNull();
});

it('records skipped items in the meta and the log', function () {
    $run = ($this->startRun)();

    $run->noteSkipped('No data available', ['report' => 7]);

    expect($run->job->refresh()->meta['skipped'])->toBe([
        ['report' => 7, 'reason' => 'No data available'],
    ])->and($run->job->getLogContents())->toContain('Skipped: No data available');
});

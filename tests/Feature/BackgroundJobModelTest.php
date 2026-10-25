<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\CustomBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\OtherJobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Report;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    File::deleteDirectory(BackgroundJobs::logPath());

    $this->owner = $this->user();
});

describe('generateFor()', function () {
    it('makes a waiting job for a model', function () {
        $report = Report::query()->create(['name' => 'Q3 Report']);

        $job = BackgroundJob::generateFor($report, JobType::ImportReports, ['file' => 'q3.csv'], userId: $this->owner->id);

        expect($job->refresh())
            ->status->toBe(BackgroundJobStatus::Waiting)
            ->type->toBe(JobType::ImportReports)
            ->jobable->is($report)->toBeTrue()
            ->user_id->toBe($this->owner->id)
            ->meta->toBe(['file' => 'q3.csv']);
    });

    it('makes the logged-in user the owner', function () {
        $this->actingAs($this->owner);

        expect(BackgroundJob::generateFor(null, JobType::ImportReports)->user_id)->toBe($this->owner->id);
    });

    it('makes a job without an owner', function () {
        $this->actingAs($this->owner);

        expect(BackgroundJob::generateFor(null, JobType::ImportReports, withoutOwner: true)->user_id)->toBeNull();
    });

    it('stores the backing value of the type', function () {
        $job = BackgroundJob::generateFor(null, JobType::ExportReports);

        expect($job->getRawOriginal('type'))->toBe('export_reports');
    });
});

describe('types', function () {
    it('reads a type of a registered enum', function () {
        BackgroundJobs::registerTypes(OtherJobType::class);

        $job = BackgroundJob::factory()->ofType(OtherJobType::SendNewsletter)->create();

        expect($job->refresh()->type)->toBe(OtherJobType::SendNewsletter)
            ->and($job->typeLabel())->toBe('Send Newsletter');
    });

    it('still loads a job when its type is removed', function () {
        $job = BackgroundJob::factory()->create();
        BackgroundJobs::table()->where('id', $job->id)->update(['type' => 'old_cleanup_task']);

        $job->refresh();

        expect($job->type)->toBeNull()
            ->and($job->typeLabel())->toBe('Old Cleanup Task');
    });

    it('finds jobs of a type', function () {
        BackgroundJob::factory()->ofType(JobType::ImportReports)->create();
        $export = BackgroundJob::factory()->ofType(JobType::ExportReports)->create();

        expect(BackgroundJob::query()->ofType(JobType::ExportReports)->pluck('id')->all())->toBe([$export->id])
            ->and(BackgroundJob::query()->ofType('export_reports')->count())->toBe(1);
    });
});

describe('status changes', function () {
    it('starts only a waiting job', function () {
        $job = BackgroundJob::factory()->finished()->create();

        expect($job->start()->status)->toBe(BackgroundJobStatus::Finished);
    });

    it('records when a job finishes', function () {
        $job = BackgroundJob::factory()->create()->finish();

        expect($job->status)->toBe(BackgroundJobStatus::Finished)
            ->and($job->finished_at)->not->toBeNull();
    });

    it('records why a job failed', function () {
        $job = BackgroundJob::factory()->create()->fail('The file is empty.');

        expect($job->status)->toBe(BackgroundJobStatus::Failed)
            ->and($job->failure_reason)->toBe('The file is empty.');
    });
});

describe('ownership', function () {
    it('belongs to its owner only', function () {
        $job = BackgroundJob::factory()->for($this->owner)->create();

        expect($job->isOwnedBy($this->owner))->toBeTrue()
            ->and($job->isOwnedBy($this->user('Grace Hopper')))->toBeFalse();
    });

    it('belongs to nobody when it has no owner', function () {
        $job = BackgroundJob::factory()->withoutOwner()->create();

        expect($job->isOwnedBy($this->owner))->toBeFalse();
    });

    it('finds the jobs of the logged-in user', function () {
        $mine = BackgroundJob::factory()->for($this->owner)->create();
        BackgroundJob::factory()->for($this->user('Grace Hopper'))->create();
        BackgroundJob::factory()->withoutOwner()->create();

        $this->actingAs($this->owner);

        expect(BackgroundJob::query()->mine()->pluck('id')->all())->toBe([$mine->id])
            ->and(BackgroundJob::query()->ownedBy($this->owner)->pluck('id')->all())->toBe([$mine->id]);
    });
});

describe('labels', function () {
    it('uses the meta label first', function () {
        $report = Report::query()->create(['name' => 'Q3 Report']);
        $job = BackgroundJob::factory()->for($report, 'jobable')->create(['meta' => ['label' => 'Audience 1']]);

        expect($job->label())->toBe('Audience 1');
    });

    it('uses the name of the model next', function () {
        $report = Report::query()->create(['name' => 'Q3 Report']);
        $job = BackgroundJob::factory()->for($report, 'jobable')->create();

        expect($job->label())->toBe('Q3 Report')
            ->and($job->jobableLabel())->toBe('Report: Q3 Report')
            ->and($job->jobableUrl())->toBe("https://example.com/reports/{$report->id}");
    });

    it('falls back to the id', function () {
        $job = BackgroundJob::factory()->create();

        expect($job->label())->toBe("Job #{$job->id}")
            ->and($job->jobableLabel())->toBeNull()
            ->and($job->jobableUrl())->toBeNull();
    });

    it('keeps the label when the model is deleted', function () {
        $report = Report::query()->create(['name' => 'Q3 Report']);
        $job = BackgroundJob::factory()->for($report, 'jobable')->create();

        $report->delete();

        expect($job->refresh()->jobableLabel())->toBe("Report: {$report->id}")
            ->and($job->jobableUrl())->toBeNull();
    });
});

describe('runs', function () {
    it('knows a run and a child', function () {
        $run = BackgroundJob::factory()->create(['children_expected' => 2]);
        $child = BackgroundJob::factory()->childOf($run)->create();

        expect($run->isRun())->toBeTrue()
            ->and($run->isChild())->toBeFalse()
            ->and($child->isChild())->toBeTrue()
            ->and($child->isRun())->toBeFalse();
    });

    it('calculates the progress of a run', function () {
        $run = BackgroundJob::factory()->processing()->create([
            'children_expected' => 4,
            'children_succeeded' => 2,
            'children_failed' => 1,
        ]);

        expect($run->settledChildren())->toBe(3)
            ->and($run->progressPercentage())->toBe(75);
    });

    it('gives the progress of a standalone job', function () {
        expect(BackgroundJob::factory()->create()->progressPercentage())->toBe(0)
            ->and(BackgroundJob::factory()->finished()->create()->progressPercentage())->toBe(100);
    });

    it('finds the roots and the children', function () {
        $run = BackgroundJob::factory()->create();
        $child = BackgroundJob::factory()->childOf($run)->create();

        expect(BackgroundJob::query()->roots()->pluck('id')->all())->toBe([$run->id])
            ->and(BackgroundJob::query()->forParents($run)->pluck('id')->all())->toBe([$child->id])
            ->and(BackgroundJob::query()->forParents([$run->id])->pluck('id')->all())->toBe([$child->id]);
    });
});

describe('status scopes', function () {
    it('finds jobs by status', function () {
        $waiting = BackgroundJob::factory()->waiting()->create();
        $processing = BackgroundJob::factory()->processing()->create();
        $finished = BackgroundJob::factory()->finished()->create();
        $failed = BackgroundJob::factory()->failed()->create();

        expect(BackgroundJob::query()->waiting()->pluck('id')->all())->toBe([$waiting->id])
            ->and(BackgroundJob::query()->processing()->pluck('id')->all())->toBe([$processing->id])
            ->and(BackgroundJob::query()->finished()->pluck('id')->all())->toBe([$finished->id])
            ->and(BackgroundJob::query()->failed()->pluck('id')->all())->toBe([$failed->id])
            ->and(BackgroundJob::query()->notFinished()->pluck('id')->all())->toBe([$waiting->id, $processing->id])
            ->and(BackgroundJob::query()->withStatus(BackgroundJobStatus::Finished, BackgroundJobStatus::Failed)->pluck('id')->all())->toBe([$finished->id, $failed->id]);
    });

    /**
     * The scope must group its conditions. Otherwise an OR leaks out and
     * another condition on the query does not apply.
     */
    it('keeps the not-finished condition in its group', function () {
        $mine = BackgroundJob::factory()->for($this->owner)->processing()->create();
        BackgroundJob::factory()->for($this->user('Grace Hopper'))->waiting()->create();

        expect(BackgroundJob::query()->ownedBy($this->owner)->notFinished()->pluck('id')->all())->toBe([$mine->id]);
    });

    it('ignores stale jobs', function () {
        BackgroundJob::factory()->processing()->create(['created_at' => now()->subHours(7)]);
        $recent = BackgroundJob::factory()->processing()->create();

        expect(BackgroundJob::query()->startedRecently()->pluck('id')->all())->toBe([$recent->id]);
    });
});

describe('meta', function () {
    it('updates one item', function () {
        $job = BackgroundJob::factory()->create(['meta' => ['a' => 1]]);

        expect($job->updateMetaItem('b', 2)->refresh()->meta)->toBe(['a' => 1, 'b' => 2]);
    });

    it('increments a number', function () {
        $job = BackgroundJob::factory()->create();

        $job->incrementMetaItem('rows');
        $job->incrementMetaItem('rows', 5);

        expect($job->meta['rows'])->toBe(6);
    });

    it('appends to a list', function () {
        $job = BackgroundJob::factory()->create();

        $job->appendMetaArrayItem('warnings', 'First');
        $job->appendMetaArrayItem('warnings', 'Second');

        expect($job->meta['warnings'])->toBe(['First', 'Second']);
    });
});

describe('logs', function () {
    it('writes to a log file of its own', function () {
        $job = BackgroundJob::factory()->create();

        expect($job->hasLog())->toBeFalse()
            ->and($job->getLogContents())->toBe('');

        $job->logChannel()->info('Read 10 rows.');
        $job->logChannel()->error('Row 11 is broken.');

        expect($job->hasLog())->toBeTrue()
            ->and($job->getLogPath())->toBe(BackgroundJobs::logPath()."/{$job->id}.log")
            ->and($job->getLogContents())->toContain('Read 10 rows.')
            ->and($job->getLogContentsForLevel('error'))->toContain('Row 11 is broken.')
            ->and($job->getLogContentsForLevel('error'))->not->toContain('Read 10 rows.')
            ->and($job->getLogContentsForLevel('all'))->toContain('Read 10 rows.');
    });
});

describe('a custom model', function () {
    beforeEach(function () {
        config(['background-jobs.model' => CustomBackgroundJob::class]);
    });

    it('uses the model from the config in the factory', function () {
        expect(BackgroundJob::factory()->create())->toBeInstanceOf(CustomBackgroundJob::class)
            ->and(CustomBackgroundJob::factory()->create())->toBeInstanceOf(CustomBackgroundJob::class);
    });

    it('keeps the status event when the model has its own booted() method', function () {
        $job = CustomBackgroundJob::factory()->for($this->owner)->create();

        $job->finish();

        expect(CustomBackgroundJob::$bootedCalls)->toBeGreaterThan(0);
        Notification::assertSentTo($this->owner, config('background-jobs.notifications.succeeded'));
    });

    it('uses the url of the custom model', function () {
        $job = CustomBackgroundJob::factory()->create();

        expect($job->url())->toBe("https://example.com/custom-jobs/{$job->id}");
    });
});

it('uses the url resolver', function () {
    $job = BackgroundJob::factory()->create();

    expect($job->url())->toBeNull();

    BackgroundJobs::resolveUrlUsing(fn (BackgroundJob $job) => "/jobs/{$job->id}");

    expect($job->url())->toBe("/jobs/{$job->id}");
});

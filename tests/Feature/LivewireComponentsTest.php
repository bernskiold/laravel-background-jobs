<?php

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire\JobDetail;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire\JobList;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire\ReportJobs;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire\RunChildren;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Report;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();
    $this->other = $this->user('Grace Hopper');

    Gate::define('viewAny', fn () => true);
    Gate::define('view', fn ($user, BackgroundJob $job) => $job->isOwnedBy($user));
    Gate::define('update', fn ($user, BackgroundJob $job) => $job->isOwnedBy($user));
    Gate::define('viewAllJobs', fn ($user) => $user->name === 'Grace Hopper');

    $this->actingAs($this->owner);
});

describe('the job list', function () {
    it('shows only the jobs of the user', function () {
        $mine = BackgroundJob::factory()->for($this->owner)->create();
        $theirs = BackgroundJob::factory()->for($this->other)->create();

        Livewire::test(JobList::class)
            ->assertSee("[job-{$mine->id}]")
            ->assertDontSee("[job-{$theirs->id}]");
    });

    it('shows all the jobs to a user who can see all jobs', function () {
        $mine = BackgroundJob::factory()->for($this->owner)->create();
        $system = BackgroundJob::factory()->withoutOwner()->create();

        $this->actingAs($this->other);

        Livewire::test(JobList::class)
            ->assertSee("[job-{$mine->id}]")
            ->assertSee("[job-{$system->id}]");
    });

    it('shows the children of runs only on request', function () {
        $run = BackgroundJob::factory()->for($this->owner)->create();
        $child = BackgroundJob::factory()->childOf($run)->create();

        Livewire::test(JobList::class)
            ->assertSee("[job-{$run->id}]")
            ->assertDontSee("[job-{$child->id}]")
            ->set('includeChildren', true)
            ->assertSee("[job-{$child->id}]");
    });

    it('refuses a user who cannot see the list', function () {
        Gate::define('viewAny', fn () => false);

        Livewire::test(JobList::class)->assertForbidden();
    });

    it('refreshes on the shared channel', function () {
        expect(Livewire::test(JobList::class)->instance()->getListeners())
            ->toHaveKey('echo-private:background-job-changes,.background-job.status-changed');
    });
});

describe('the job detail', function () {
    it('shows a job of the user', function () {
        $job = BackgroundJob::factory()->for($this->owner)->create();

        Livewire::test(JobDetail::class, ['jobId' => $job->id])
            ->assertSee('Import Reports');
    });

    it('refuses a job of another user', function () {
        $job = BackgroundJob::factory()->for($this->other)->create();

        Livewire::test(JobDetail::class, ['jobId' => $job->id])->assertForbidden();
    });

    it('marks a running job as failed', function () {
        $job = BackgroundJob::factory()->for($this->owner)->processing()->create();

        Livewire::test(JobDetail::class, ['jobId' => $job->id])
            ->call('markAsFailed')
            ->assertSet('notified', true);

        expect($job->refresh())
            ->status->toBe(BackgroundJobStatus::Failed)
            ->failure_reason->toBe('Manually failed by Ada Lovelace');
    });

    it('refuses to mark the job as failed without permission', function () {
        Gate::define('update', fn () => false);

        $job = BackgroundJob::factory()->for($this->owner)->processing()->create();

        Livewire::test(JobDetail::class, ['jobId' => $job->id])
            ->call('markAsFailed')
            ->assertForbidden();

        expect($job->refresh()->status)->toBe(BackgroundJobStatus::Processing);
    });

    it('filters the log by level', function () {
        $job = BackgroundJob::factory()->for($this->owner)->create();
        $job->logChannel()->info('Read 10 rows.');
        $job->logChannel()->error('Row 11 is broken.');

        Livewire::test(JobDetail::class, ['jobId' => $job->id])
            ->assertSee('Read 10 rows.')
            ->set('logFilter', 'error')
            ->assertSee('Row 11 is broken.')
            ->assertDontSee('Read 10 rows.')
            ->set('logFilter', 'not-a-level')
            ->assertSee('Read 10 rows.');
    });

    it('listens on the channel of the run', function () {
        $run = BackgroundJob::factory()->for($this->owner)->create(['children_expected' => 1]);

        expect(Livewire::test(JobDetail::class, ['jobId' => $run->id])->instance()->getListeners())
            ->toHaveKeys([
                "echo-private:background-job-status.{$run->id},.background-job.status-changed",
                "echo-private:background-job-run.{$run->id},.background-job.status-changed",
            ]);
    });
});

describe('the children of a run', function () {
    it('filters the children by status', function () {
        $run = BackgroundJob::factory()->for($this->owner)->create(['children_expected' => 2]);
        BackgroundJob::factory()->childOf($run)->finished()->create(['meta' => ['label' => 'Good child']]);
        BackgroundJob::factory()->childOf($run)->failed()->create(['meta' => ['label' => 'Bad child']]);

        Livewire::test(RunChildren::class, ['runId' => $run->id])
            ->assertSee('Good child')
            ->assertSee('Bad child')
            ->set('statusFilter', 'failed')
            ->assertDontSee('Good child')
            ->assertSee('Bad child');
    });

    it('refuses a run of another user', function () {
        $run = BackgroundJob::factory()->for($this->other)->create();

        Livewire::test(RunChildren::class, ['runId' => $run->id])->assertForbidden();
    });
});

describe('the jobs of a model', function () {
    it('shows the jobs of the model only', function () {
        $report = Report::query()->create(['name' => 'Q3 Report']);
        $job = BackgroundJob::generateFor($report, JobType::ImportReports);
        $other = BackgroundJob::factory()->create();

        $component = Livewire::test(ReportJobs::class, ['modelType' => $report->getMorphClass(), 'modelId' => $report->id])
            ->assertSee("Import Reports #{$job->id}")
            ->assertDontSee("#{$other->id}");

        expect($component->instance()->hasRunningJobs())->toBeTrue();

        $job->finish();

        expect(Livewire::test(ReportJobs::class, ['modelType' => $report->getMorphClass(), 'modelId' => $report->id])->instance()->hasRunningJobs())
            ->toBeFalse();
    });
});

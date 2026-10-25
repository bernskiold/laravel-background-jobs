<?php

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Report;

beforeEach(function () {
    $this->report = Report::query()->create(['name' => 'Q3 Report']);
});

it('has the jobs of the model', function () {
    $job = BackgroundJob::generateFor($this->report, JobType::ImportReports);
    BackgroundJob::factory()->create();

    expect($this->report->backgroundJobs()->pluck('id')->all())->toBe([$job->id])
        ->and(BackgroundJob::query()->forModel($this->report)->pluck('id')->all())->toBe([$job->id])
        ->and(BackgroundJob::query()->forModel($this->report->id, $this->report->getMorphClass())->pluck('id')->all())->toBe([$job->id]);
});

it('knows when a job of a type still runs', function () {
    BackgroundJob::generateFor($this->report, JobType::ImportReports)->start();

    expect($this->report->hasUnfinishedBackgroundJob(JobType::ImportReports))->toBeTrue()
        ->and($this->report->hasUnfinishedBackgroundJob(JobType::ExportReports))->toBeFalse();
});

it('does not count a finished or stale job', function () {
    BackgroundJob::generateFor($this->report, JobType::ImportReports)->finish();
    BackgroundJob::factory()->for($this->report, 'jobable')->processing()->create([
        'type' => JobType::ExportReports,
        'created_at' => now()->subDay(),
    ]);

    expect($this->report->hasUnfinishedBackgroundJob(JobType::ImportReports))->toBeFalse()
        ->and($this->report->hasUnfinishedBackgroundJob(JobType::ExportReports))->toBeFalse();
});

it('lists the models with a running job of a type', function () {
    $other = Report::query()->create(['name' => 'Q4 Report']);

    BackgroundJob::generateFor($this->report, JobType::ImportReports)->start();
    BackgroundJob::generateFor($other, JobType::ImportReports)->finish();

    expect(Report::idsWithUnfinishedBackgroundJob(JobType::ImportReports)->all())->toBe([$this->report->id]);
});

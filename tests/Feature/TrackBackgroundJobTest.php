<?php

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Jobs\Middleware\TrackBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Jobs\ImportReportJob;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    ImportReportJob::$runs = 0;

    $this->backgroundJob = BackgroundJob::factory()->create();
});

it('marks the background job as processing', function () {
    ImportReportJob::dispatch($this->backgroundJob);

    expect($this->backgroundJob->refresh()->status)->toBe(BackgroundJobStatus::Processing)
        ->and(ImportReportJob::$runs)->toBe(1);
});

it('finishes the background job when you ask for it', function () {
    ImportReportJob::dispatch($this->backgroundJob, finish: true);

    expect($this->backgroundJob->refresh()->status)->toBe(BackgroundJobStatus::Finished);
});

it('does not run when the background job has failed', function () {
    $this->backgroundJob->fail('A sibling failed.');

    ImportReportJob::dispatch($this->backgroundJob, finish: true);

    expect(ImportReportJob::$runs)->toBe(0)
        ->and($this->backgroundJob->refresh()->status)->toBe(BackgroundJobStatus::Failed)
        ->and($this->backgroundJob->logText())->toContain('Skipped ImportReportJob, because the background job has failed.');
});

it('fails the background job when the queued job throws', function () {
    expect(fn () => ImportReportJob::dispatch($this->backgroundJob, throws: true))
        ->toThrow(RuntimeException::class, 'The file is empty.');

    expect($this->backgroundJob->refresh())
        ->status->toBe(BackgroundJobStatus::Failed)
        ->failure_reason->toBe('The file is empty.');
});

it('leaves the failure to failed() when the queue must retry', function () {
    $queuedJob = new ImportReportJob($this->backgroundJob, throws: true, failOnException: false);
    $middleware = new TrackBackgroundJob(failOnException: false);

    expect(fn () => $middleware->handle($queuedJob, fn (ImportReportJob $job) => $job->handle()))
        ->toThrow(RuntimeException::class);

    expect($this->backgroundJob->refresh()->status)->toBe(BackgroundJobStatus::Processing);

    $queuedJob->failed(new RuntimeException('Out of attempts.'));

    expect($this->backgroundJob->refresh())
        ->status->toBe(BackgroundJobStatus::Failed)
        ->failure_reason->toBe('Out of attempts.');
});

it('does not change a settled background job in failed()', function () {
    $this->backgroundJob->finish();

    (new ImportReportJob($this->backgroundJob))->failed(new RuntimeException('Late.'));

    expect($this->backgroundJob->refresh()->status)->toBe(BackgroundJobStatus::Finished);
});

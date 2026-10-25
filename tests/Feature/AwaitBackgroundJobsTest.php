<?php

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Jobs\AwaitBackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function () {
    Notification::fake();
});

/**
 * @param  array<int, int>  $backgroundJobIds
 */
function awaitJobWithQueueJob(array $backgroundJobIds, MockInterface $queueJob, int $attempts = 1): AwaitBackgroundJobs
{
    $queueJob->shouldReceive('attempts')->andReturn($attempts);

    $job = new AwaitBackgroundJobs($backgroundJobIds);
    $job->setJob($queueJob);

    return $job;
}

it('polls again while a job still runs', function (BackgroundJobStatus $status) {
    $backgroundJob = BackgroundJob::factory()->create(['status' => $status]);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once();

    awaitJobWithQueueJob([$backgroundJob->id], $queueJob)->handle();
})->with([
    'waiting' => BackgroundJobStatus::Waiting,
    'processing' => BackgroundJobStatus::Processing,
]);

it('stops when all the jobs are settled', function (BackgroundJobStatus $status) {
    $backgroundJob = BackgroundJob::factory()->create(['status' => $status]);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldNotReceive('release');

    awaitJobWithQueueJob([$backgroundJob->id], $queueJob)->handle();
})->with([
    'finished' => BackgroundJobStatus::Finished,
    'failed' => BackgroundJobStatus::Failed,
    'completed with errors' => BackgroundJobStatus::CompletedWithErrors,
]);

it('waits for the slowest job', function () {
    $finished = BackgroundJob::factory()->finished()->create();
    $running = BackgroundJob::factory()->processing()->create();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once();

    awaitJobWithQueueJob([$finished->id, $running->id], $queueJob)->handle();
});

it('makes the poll interval longer each time', function (int $attempts, int $expectedDelay) {
    $running = BackgroundJob::factory()->processing()->create();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with($expectedDelay);

    awaitJobWithQueueJob([$running->id], $queueJob, $attempts)->handle();
})->with([
    'first poll' => [1, 15],
    'second poll' => [2, 30],
    'fifth poll' => [5, 240],
    'maximum' => [10, 300],
]);

it('has nothing to wait for', function (array $backgroundJobIds) {
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldNotReceive('release');

    awaitJobWithQueueJob($backgroundJobIds, $queueJob)->handle();
})->with([
    'no jobs' => [[]],
    'a job that does not exist' => [[999999]],
]);

it('stops to wait after the stale window', function () {
    config(['background-jobs.stale_after_hours' => 2]);

    $this->travelTo(now()->startOfMinute());

    expect((new AwaitBackgroundJobs([1]))->retryUntil()->getTimestamp())->toBe(now()->addHours(2)->getTimestamp());
});

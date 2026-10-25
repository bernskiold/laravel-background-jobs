<?php

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->path = tempnam(sys_get_temp_dir(), 'background-job-');
});

afterEach(function () {
    @unlink($this->path);
});

it('deletes the file when the job settles', function (string $method) {
    $job = BackgroundJob::factory()->create(['meta' => ['file_path' => $this->path]]);

    $job->{$method}();

    expect(file_exists($this->path))->toBeFalse();
})->with(['finish', 'fail']);

it('keeps the file while the job runs', function () {
    BackgroundJob::factory()->create(['meta' => ['file_path' => $this->path]])->start();

    expect(file_exists($this->path))->toBeTrue();
});

it('keeps the file when the feature is disabled', function () {
    config(['background-jobs.remove_temporary_files' => false]);

    BackgroundJob::factory()->create(['meta' => ['file_path' => $this->path]])->finish();

    expect(file_exists($this->path))->toBeTrue();
});

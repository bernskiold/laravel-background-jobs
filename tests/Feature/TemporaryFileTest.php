<?php

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Support\TemporaryFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

beforeEach(function () {
    Notification::fake();

    // A disk that is not local, as S3 is. Each server must download a file
    // from it before a library that only reads local files can open it.
    Storage::extend('memory', function ($app, array $config) {
        $adapter = new InMemoryFilesystemAdapter;

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });

    config(['filesystems.disks.remote' => ['driver' => 'memory']]);
});

it('records a file on a disk in the meta', function () {
    $job = BackgroundJob::factory()->create(['meta' => ['label' => 'Upload']]);

    $job->setTemporaryFile('uploads/q3.csv', 'remote');

    expect($job->refresh()->meta)->toBe([
        'label' => 'Upload',
        'temporary_file' => ['disk' => 'remote', 'path' => 'uploads/q3.csv'],
    ])->and($job->temporaryFile())
        ->path->toBe('uploads/q3.csv')
        ->disk->toBe('remote');
});

it('reads the local path of earlier versions', function () {
    $job = BackgroundJob::factory()->create(['meta' => ['file_path' => '/tmp/q3.csv']]);

    expect($job->temporaryFile())
        ->path->toBe('/tmp/q3.csv')
        ->disk->toBeNull();
});

it('has no file when the meta does not name one', function () {
    expect(BackgroundJob::factory()->create()->temporaryFile())->toBeNull();
});

it('gives the path of a file on a local disk', function () {
    Storage::fake('uploads');
    Storage::disk('uploads')->put('q3.csv', 'a,b');

    $file = new TemporaryFile('q3.csv', 'uploads');

    expect($file->localPath())->toBe(Storage::disk('uploads')->path('q3.csv'))
        ->and($file->exists())->toBeTrue();
});

it('downloads a file from a remote disk one time', function () {
    Storage::disk('remote')->put('uploads/q3.csv', "a,b\n1,2");

    $file = new TemporaryFile('uploads/q3.csv', 'remote');
    $path = $file->localPath();

    expect($path)->toEndWith('.csv')
        ->and(file_get_contents($path))->toBe("a,b\n1,2")
        ->and($file->localPath())->toBe($path);

    $file->cleanUp();

    expect(file_exists($path))->toBeFalse()
        ->and(Storage::disk('remote')->exists('uploads/q3.csv'))->toBeTrue();
});

it('downloads to the configured directory', function () {
    $directory = sys_get_temp_dir().'/background-job-downloads';
    config(['background-jobs.temporary_files.local_directory' => $directory]);
    Storage::disk('remote')->put('uploads/q3.csv', 'a,b');

    $file = new TemporaryFile('uploads/q3.csv', 'remote');

    expect($file->localPath())->toStartWith($directory.'/');

    $file->cleanUp();
});

it('says when a remote file does not exist', function () {
    (new TemporaryFile('missing.csv', 'remote'))->localPath();
})->throws(RuntimeException::class);

it('deletes a file on a disk when the job settles', function () {
    Storage::disk('remote')->put('uploads/q3.csv', 'a,b');

    $job = BackgroundJob::factory()->create();
    $job->setTemporaryFile('uploads/q3.csv', 'remote');

    $job->finish();

    expect(Storage::disk('remote')->exists('uploads/q3.csv'))->toBeFalse();
});

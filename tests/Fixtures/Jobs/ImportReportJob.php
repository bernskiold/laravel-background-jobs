<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Jobs;

use Bernskiold\LaravelBackgroundJobs\Concerns\InteractsWithBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Jobs\Middleware\TrackBackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

class ImportReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithBackgroundJob, InteractsWithQueue, Queueable;

    public static int $runs = 0;

    public function __construct(
        public BackgroundJob $backgroundJob,
        public bool $throws = false,
        public bool $finish = false,
        public bool $failOnException = true,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TrackBackgroundJob(finish: $this->finish, failOnException: $this->failOnException)];
    }

    public function handle(): void
    {
        static::$runs++;

        $this->backgroundJob->logChannel()->info('Importing.');

        if ($this->throws) {
            throw new RuntimeException('The file is empty.');
        }
    }
}

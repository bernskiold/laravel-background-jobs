<?php

namespace Bernskiold\LaravelBackgroundJobs\Jobs;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Keeps a chain open until the background jobs that you give it are finished.
 *
 * An action that dispatches its own batch is done when the batch is
 * dispatched. Without this job, the next job in the chain starts while that
 * batch still runs. A run settles when its children settle, but nothing tells
 * a waiting chain. This job polls the status of the jobs, so a chain can wait
 * for work that it did not dispatch itself.
 */
class AwaitBackgroundJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Each poll uses one attempt. Thus retryUntil() limits the wait, and not
     * the number of tries.
     */
    public int $tries = 0;

    protected const FIRST_POLL_SECONDS = 15;

    /**
     * Each poll puts the remainder of the chain back on the queue. Thus the
     * interval becomes longer with each poll.
     */
    protected const MAX_POLL_SECONDS = 300;

    /**
     * @param  array<int, int>  $backgroundJobIds
     */
    public function __construct(
        public array $backgroundJobIds,
        ?string $queue = null,
    ) {
        $this->onQueue($queue);
    }

    /**
     * A job that is not finished in the stale window will not finish. The
     * wait then fails, and does not poll forever.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(BackgroundJobs::staleAfterHours());
    }

    public function handle(): void
    {
        $stillRunning = $this->backgroundJobIds !== [] && BackgroundJobs::model()::query()
            ->whereKey($this->backgroundJobIds)
            ->notFinished()
            ->exists();

        if (! $stillRunning) {
            return;
        }

        $this->release($this->nextPollInSeconds());
    }

    protected function nextPollInSeconds(): int
    {
        return (int) min(self::MAX_POLL_SECONDS, self::FIRST_POLL_SECONDS * 2 ** ($this->attempts() - 1));
    }
}

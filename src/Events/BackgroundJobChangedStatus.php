<?php

namespace Bernskiold\LaravelBackgroundJobs\Events;

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A background job changed its status.
 *
 * The event broadcasts as "background-job.status-changed". Listen for it with
 * a leading dot, for example ".background-job.status-changed", because the
 * name does not include a namespace.
 */
class BackgroundJobChangedStatus implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const BROADCAST_NAME = 'background-job.status-changed';

    public function __construct(
        public int $backgroundJobId,
        public ?BackgroundJobStatus $from,
        public BackgroundJobStatus $to,
        public ?int $parentId = null,
    ) {}

    /**
     * Each job broadcasts on its own channel.
     *
     * A run and a standalone job also broadcast on the shared channel, which
     * the job lists use. The children of a run broadcast on the channel of
     * their run, and not on the shared channel. A large run can have hundreds
     * of children, and each child on the shared channel causes a refresh of
     * every open list.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel(static::jobChannel($this->backgroundJobId))];

        if ($this->parentId !== null) {
            $channels[] = new PrivateChannel(static::runChannel($this->parentId));

            return $channels;
        }

        $channels[] = new PrivateChannel(static::listChannel());

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::BROADCAST_NAME;
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('background-jobs.broadcasting.enabled', true);
    }

    public static function jobChannel(int|string $jobId): string
    {
        return "background-job-status.{$jobId}";
    }

    public static function runChannel(int|string $runId): string
    {
        return "background-job-run.{$runId}";
    }

    public static function listChannel(): string
    {
        return 'background-job-changes';
    }
}

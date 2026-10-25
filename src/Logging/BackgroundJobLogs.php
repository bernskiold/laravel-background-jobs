<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Gives each background job one logger, and writes the buffered entries to the
 * log store.
 *
 * A worker keeps the loggers only while a queued job runs. The service provider
 * calls flushAll() when the queued job ends and when the process stops.
 */
class BackgroundJobLogs
{
    /**
     * @var array<int|string, array{logger: Logger, handler: LogStoreHandler}>
     */
    protected array $loggers = [];

    public function __construct(protected BackgroundJobLogStore $store) {}

    public function store(): BackgroundJobLogStore
    {
        return $this->store;
    }

    public function logger(int|string $jobId): LoggerInterface
    {
        if (! isset($this->loggers[$jobId])) {
            $handler = new LogStoreHandler(
                logs: $this,
                jobId: $jobId,
                bufferSize: (int) config('background-jobs.logs.buffer_size', 100),
                flushInterval: (float) config('background-jobs.logs.flush_interval', 2),
            );

            $this->loggers[$jobId] = [
                'logger' => new Logger('background-job', [$handler]),
                'handler' => $handler,
            ];
        }

        return $this->loggers[$jobId]['logger'];
    }

    /**
     * Write the buffered entries of one job.
     */
    public function flush(int|string $jobId): void
    {
        if (isset($this->loggers[$jobId])) {
            $this->loggers[$jobId]['handler']->flush();
        }
    }

    /**
     * Write the buffered entries of all jobs, and forget the loggers.
     */
    public function flushAll(): void
    {
        $loggers = $this->loggers;
        $this->loggers = [];

        foreach ($loggers as $logger) {
            $logger['handler']->flush();
        }
    }

    /**
     * Write entries to the store. Each write also updates "updated_at" of the
     * job. A job that writes to its log thus shows that it is still alive.
     *
     * @param  list<LogEntry>  $entries
     */
    public function write(int|string $jobId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->store->write($jobId, $entries);

        BackgroundJobs::table()
            ->where('id', $jobId)
            ->update(['updated_at' => now()]);
    }
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Contracts;

use Bernskiold\LaravelBackgroundJobs\Logging\LogEntry;

/**
 * The place where the logs of the background jobs are kept.
 *
 * The package writes the log through a buffer. Thus write() receives the
 * entries in batches, and not one by one.
 */
interface BackgroundJobLogStore
{
    /**
     * @param  list<LogEntry>  $entries
     */
    public function write(int|string $jobId, array $entries): void;

    /**
     * The latest entries of a job, oldest first.
     *
     * @param  string|null  $level  Only entries of this level, for example "error".
     * @param  int|null  $limit  The maximum number of entries. The store keeps the latest ones.
     * @return list<LogEntry>
     */
    public function read(int|string $jobId, ?string $level = null, ?int $limit = null): array;

    public function exists(int|string $jobId): bool;

    public function delete(int|string $jobId): void;

    /**
     * Delete the logs of many jobs.
     *
     * @param  list<int|string>  $jobIds
     */
    public function deleteForJobs(array $jobIds): void;
}

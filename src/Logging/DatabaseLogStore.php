<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Keeps the logs in a database table.
 *
 * All the servers can read the logs, and a log is deleted with its job. Use a
 * separate connection in the config when a job logs in a transaction that can
 * roll back. Otherwise the log entries roll back too.
 */
class DatabaseLogStore implements BackgroundJobLogStore
{
    /**
     * The longest message that the store keeps, in bytes.
     */
    protected const MAX_MESSAGE_BYTES = 60_000;

    public function write(int|string $jobId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $rows = array_map(fn (LogEntry $entry) => [
            'background_job_id' => $jobId,
            'level' => $entry->level,
            'message' => mb_strcut($entry->message, 0, self::MAX_MESSAGE_BYTES),
            'context' => $entry->context === []
                ? null
                : json_encode($entry->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'logged_at' => ($entry->loggedAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
        ], $entries);

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->query()->insert($chunk);
        }
    }

    public function read(int|string $jobId, ?string $level = null, ?int $limit = null): array
    {
        $rows = $this->query()
            ->where('background_job_id', $jobId)
            ->when($level !== null, fn (Builder $query) => $query->where('level', $level))
            ->orderByDesc('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->get(['level', 'message', 'context', 'logged_at']);

        return $rows->reverse()
            ->map(fn (object $row) => new LogEntry(
                level: $row->level,
                message: $row->message,
                context: $row->context ? (array) json_decode($row->context, true) : [],
                loggedAt: CarbonImmutable::parse($row->logged_at),
            ))
            ->values()
            ->all();
    }

    public function exists(int|string $jobId): bool
    {
        return $this->query()->where('background_job_id', $jobId)->exists();
    }

    public function delete(int|string $jobId): void
    {
        $this->query()->where('background_job_id', $jobId)->delete();
    }

    protected function query(): Builder
    {
        $connection = config('background-jobs.logs.connection')
            ?? BackgroundJobs::newModel()->getConnectionName();

        return app('db')->connection($connection)->table(config('background-jobs.logs.table', 'background_job_logs'));
    }
}

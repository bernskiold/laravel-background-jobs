<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Keeps each log in a local file of its own.
 *
 * Only use this store when one server runs the web requests and the queue
 * workers. Another server cannot read the file.
 */
class FileLogStore implements BackgroundJobLogStore
{
    /**
     * A line from this store, or from the Monolog files of earlier versions:
     * "[2026-10-25 12:00:00] ERROR: Message" or
     * "[2026-10-25T12:00:00.000000+00:00] background-job.ERROR: Message".
     */
    protected const LINE_PATTERN = '/^\[(?<date>[^\]]+)\] (?:[\w-]+\.)?(?<level>[A-Z]+): (?<message>.*)$/';

    public function write(int|string $jobId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        File::ensureDirectoryExists(BackgroundJobs::logPath());

        $lines = array_map(fn (LogEntry $entry) => $entry->format().PHP_EOL, $entries);

        File::append($this->path($jobId), implode('', $lines));
    }

    public function read(int|string $jobId, ?string $level = null, ?int $limit = null): array
    {
        if (! $this->exists($jobId)) {
            return [];
        }

        $entries = static::parse((string) File::get($this->path($jobId)));

        if ($level !== null) {
            $entries = array_values(array_filter($entries, fn (LogEntry $entry) => $entry->level === $level));
        }

        return $limit === null ? $entries : array_slice($entries, -$limit);
    }

    public function exists(int|string $jobId): bool
    {
        return File::exists($this->path($jobId));
    }

    public function delete(int|string $jobId): void
    {
        File::delete($this->path($jobId));
    }

    public function path(int|string $jobId): string
    {
        return BackgroundJobs::logPath()."/{$jobId}.log";
    }

    /**
     * Read the entries from the text of a log file. A line that does not start
     * a new entry, for example a line of a stack trace, belongs to the entry
     * before it.
     *
     * @return list<LogEntry>
     */
    public static function parse(string $contents): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match(self::LINE_PATTERN, $line, $matches)) {
                if ($current !== null) {
                    $entries[] = static::toEntry($current);
                }

                $current = [
                    'date' => $matches['date'],
                    'level' => strtolower($matches['level']),
                    'message' => $matches['message'],
                ];

                continue;
            }

            if ($current !== null && $line !== '') {
                $current['message'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $entries[] = static::toEntry($current);
        }

        return $entries;
    }

    /**
     * @param  array{date: string, level: string, message: string}  $line
     */
    protected static function toEntry(array $line): LogEntry
    {
        try {
            $loggedAt = CarbonImmutable::parse($line['date']);
        } catch (Throwable) {
            $loggedAt = null;
        }

        return new LogEntry(level: $line['level'], message: $line['message'], loggedAt: $loggedAt);
    }
}

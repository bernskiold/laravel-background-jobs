<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Carbon\CarbonImmutable;
use Generator;
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

        $entries = iterator_to_array(static::stream($this->path($jobId)), false);

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

    public function deleteForJobs(array $jobIds): void
    {
        foreach ($jobIds as $jobId) {
            $this->delete($jobId);
        }
    }

    public function path(int|string $jobId): string
    {
        return BackgroundJobs::logPath()."/{$jobId}.log";
    }

    /**
     * Read the entries from the text of a log file.
     *
     * @return list<LogEntry>
     */
    public static function parse(string $contents): array
    {
        return iterator_to_array(static::entries(preg_split('/\R/', $contents) ?: []), false);
    }

    /**
     * Read the entries from a log file one at a time. Only one entry is in
     * memory at a time, so the size of the file is not important.
     *
     * @return Generator<int, LogEntry>
     */
    public static function stream(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            yield from static::entries(static::lines($handle));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Make entries from the lines of a log file. A line that does not start a
     * new entry, for example a line of a stack trace, belongs to the entry
     * before it.
     *
     * @param  iterable<string>  $lines
     * @return Generator<int, LogEntry>
     */
    protected static function entries(iterable $lines): Generator
    {
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::LINE_PATTERN, $line, $matches)) {
                if ($current !== null) {
                    yield static::toEntry($current);
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
            yield static::toEntry($current);
        }
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    protected static function lines($handle): Generator
    {
        while (($line = fgets($handle)) !== false) {
            yield rtrim($line, "\r\n");
        }
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

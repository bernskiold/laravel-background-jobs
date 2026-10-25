<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Carbon\CarbonImmutable;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Monolog handler that keeps the records of one job in a buffer and writes
 * them to the log manager in batches.
 *
 * The buffer is written when it is full, when the oldest record is older than
 * the flush interval, and at once for an error or a more serious level. The
 * manager also writes all buffers when a queued job ends and when the process
 * stops.
 */
class LogStoreHandler extends AbstractProcessingHandler
{
    /**
     * @var list<LogEntry>
     */
    protected array $buffer = [];

    protected ?float $bufferStartedAt = null;

    protected NormalizerFormatter $normalizer;

    public function __construct(
        protected BackgroundJobLogs $logs,
        protected int|string $jobId,
        protected int $bufferSize = 100,
        protected float $flushInterval = 2.0,
    ) {
        parent::__construct(Level::Debug);

        $this->normalizer = new NormalizerFormatter('Y-m-d H:i:s');
    }

    protected function write(LogRecord $record): void
    {
        /** @var array{context: array<string, mixed>} $normalized */
        $normalized = $this->normalizer->format($record);

        $this->buffer[] = new LogEntry(
            level: strtolower($record->level->getName()),
            message: $record->message,
            context: $normalized['context'],
            loggedAt: CarbonImmutable::instance($record->datetime),
        );

        $this->bufferStartedAt ??= microtime(true);

        if ($this->shouldFlush($record)) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $entries = $this->buffer;
        $this->buffer = [];
        $this->bufferStartedAt = null;

        $this->logs->write($this->jobId, $entries);
    }

    public function close(): void
    {
        $this->flush();
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (\Throwable) {
            // A destructor must not throw. The process is ending anyway.
        }
    }

    protected function shouldFlush(LogRecord $record): bool
    {
        return count($this->buffer) >= $this->bufferSize
            || $record->level->isHigherThan(Level::Warning)
            || (microtime(true) - (float) $this->bufferStartedAt) >= $this->flushInterval;
    }
}

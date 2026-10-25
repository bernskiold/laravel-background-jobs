<?php

namespace Bernskiold\LaravelBackgroundJobs\Logging;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One line of the log of a background job.
 */
final class LogEntry
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $context = [],
        public readonly ?CarbonImmutable $loggedAt = null,
    ) {}

    /**
     * The entry as one human-readable line, for example
     * "[2026-10-25 12:00:00] ERROR: Row 11 is broken. {"row":11}".
     */
    public function format(): string
    {
        $line = sprintf(
            '[%s] %s: %s',
            ($this->loggedAt ?? CarbonImmutable::now())->format('Y-m-d H:i:s'),
            strtoupper($this->level),
            $this->message,
        );

        if ($this->context !== []) {
            $line .= ' '.json_encode($this->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return $line;
    }

    public function loggedAtString(): string
    {
        return ($this->loggedAt ?? CarbonImmutable::now())->format(DateTimeInterface::ATOM);
    }
}

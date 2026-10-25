<?php

namespace Bernskiold\LaravelBackgroundJobs\Enums;

enum BackgroundJobStatus: int
{
    case Waiting = 0;

    case Processing = 1;

    case Finished = 2;

    case Failed = 3;

    /**
     * A run where some children are finished and some failed. Only the
     * roll-up of a run sets this status.
     */
    case CompletedWithErrors = 4;

    /**
     * @return list<self>
     */
    public static function running(): array
    {
        return [self::Waiting, self::Processing];
    }

    /**
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::Finished, self::Failed, self::CompletedWithErrors];
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => __('Waiting'),
            self::Processing => __('Processing'),
            self::Finished => __('Finished'),
            self::Failed => __('Failed'),
            self::CompletedWithErrors => __('Completed with errors'),
        };
    }

    public function hasFinishedRunning(): bool
    {
        return in_array($this, self::settled(), true);
    }

    public function isRunning(): bool
    {
        return in_array($this, self::running(), true);
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    public function isPartiallyFailed(): bool
    {
        return $this === self::CompletedWithErrors;
    }
}

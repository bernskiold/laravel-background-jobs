<?php

namespace Bernskiold\LaravelBackgroundJobs\Enums;

/**
 * The outcome that a notification reports.
 */
enum BackgroundJobOutcome: string
{
    /**
     * A standalone job is finished.
     */
    case Succeeded = 'succeeded';

    /**
     * A standalone job failed.
     */
    case Failed = 'failed';

    /**
     * A run settled: all children finished, some failed, or all failed.
     */
    case RunCompleted = 'run_completed';
}

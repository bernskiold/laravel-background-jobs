<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures;

use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Contracts\ChoosesBackgroundJobNotifications;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobOutcome;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Notifications\Notification;

/**
 * A job type that only tells its owner about failures, and sends its own email
 * for a nightly sync.
 */
enum QuietJobType: string implements BackgroundJobType, ChoosesBackgroundJobNotifications
{
    case Cleanup = 'cleanup';
    case NightlySync = 'nightly_sync';

    public function label(): string
    {
        return match ($this) {
            self::Cleanup => 'Cleanup',
            self::NightlySync => 'Nightly Sync',
        };
    }

    public function notificationFor(BackgroundJobOutcome $outcome, BackgroundJob $job): Notification|false|null
    {
        return match (true) {
            $this === self::NightlySync && $outcome === BackgroundJobOutcome::Failed => new NightlySyncFailed($job),
            $outcome === BackgroundJobOutcome::Succeeded => false,
            default => null,
        };
    }
}

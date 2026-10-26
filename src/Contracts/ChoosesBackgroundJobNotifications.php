<?php

namespace Bernskiold\LaravelBackgroundJobs\Contracts;

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobOutcome;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Notifications\Notification;

/**
 * Let a job type choose its own notifications. Implement it on the enum of the
 * job types, next to the BackgroundJobType contract.
 *
 * Return a notification to send it, false to send nothing, or null to use the
 * notification from the config.
 */
interface ChoosesBackgroundJobNotifications
{
    public function notificationFor(BackgroundJobOutcome $outcome, BackgroundJob $job): Notification|false|null;
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Actions;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;

/**
 * Sets a run to processing when its first child starts.
 *
 * The first child sets the status. For all the other children, the update does
 * nothing, because the condition only finds a run that is still waiting.
 */
class MarkBackgroundJobRunAsProcessing
{
    public function execute(int $runId): void
    {
        BackgroundJobs::table()
            ->where('id', $runId)
            ->where('status', BackgroundJobStatus::Waiting->value)
            ->update([
                'status' => BackgroundJobStatus::Processing->value,
                'updated_at' => now(),
            ]);
    }
}

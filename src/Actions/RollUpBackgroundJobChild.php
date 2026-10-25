<?php

namespace Bernskiold\LaravelBackgroundJobs\Actions;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;

/**
 * Adds a settled child to the counters of its run. Then it tries to settle the
 * run, because the child can be the last one.
 *
 * The counter update is one atomic "SET col = col + 1". When children on
 * different workers finish at the same time, the database runs their updates
 * one after the other, and no count is lost.
 */
class RollUpBackgroundJobChild
{
    public function __construct(private SettleBackgroundJobRun $settle) {}

    public function execute(BackgroundJob $child): void
    {
        if ($child->parent_id === null) {
            return;
        }

        $this->countChild($child);

        $this->settle->execute($child->parent_id);
    }

    /**
     * Count a child in the counters of its parent one time only.
     */
    private function countChild(BackgroundJob $child): void
    {
        $column = match (true) {
            $this->claimChild($child, BackgroundJobStatus::Failed) => 'children_failed',
            $this->claimChild($child, BackgroundJobStatus::Finished) => 'children_succeeded',
            default => null,
        };

        if ($column === null) {
            return;
        }

        BackgroundJobs::table()
            ->where('id', $child->parent_id)
            ->increment($column);
    }

    /**
     * The "rolled_up_at" column makes sure that a child is counted only once.
     * A second status event, a retry of the listener, or a manual "mark as
     * failed" on a finished child does not count the child again. The number
     * of changed rows tells if this call counted the child.
     */
    private function claimChild(BackgroundJob $child, BackgroundJobStatus $status): bool
    {
        return BackgroundJobs::table()
            ->where('id', $child->getKey())
            ->whereNull('rolled_up_at')
            ->where('status', $status->value)
            ->update(['rolled_up_at' => now()]) === 1;
    }
}

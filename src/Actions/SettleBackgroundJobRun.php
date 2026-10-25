<?php

namespace Bernskiold\LaravelBackgroundJobs\Actions;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;

/**
 * Sets the final status of a run when all of its children are settled. Then it
 * sends the one status event that notifies the owner.
 *
 * You can call it at any time and from any place. The claim below makes sure
 * that only one call can settle the run, also when many children finish at
 * the same time.
 *
 * Each read that controls a write is in the WHERE clause of the UPDATE, so the
 * row lock of the database serializes the calls. There is no transaction and
 * no lockForUpdate() on purpose. MySQL always reads the latest committed row
 * for the WHERE clause of an UPDATE, also under REPEATABLE READ. A read in a
 * transaction can use an old snapshot. Then the run can notify two times, or
 * it can stay unsettled forever.
 */
class SettleBackgroundJobRun
{
    /**
     * @return bool True when this call settled the run.
     */
    public function execute(int $runId): bool
    {
        if (! $this->claim($runId)) {
            // Another call settled the run, or some children are not settled.
            return false;
        }

        $run = BackgroundJobs::model()::query()->find($runId);

        if ($run === null) {
            return false;
        }

        $run->logChannel()->info(sprintf(
            'Run settled: %d succeeded, %d failed of %d expected.',
            $run->children_succeeded,
            $run->children_failed,
            $run->children_expected,
        ));

        // The claim uses the query builder, so no model event fires. This
        // event starts the broadcast and the notification.
        event(new BackgroundJobChangedStatus(
            backgroundJobId: $run->getKey(),
            from: BackgroundJobStatus::Processing,
            to: $run->status,
        ));

        return true;
    }

    /**
     * Only one call can move the run out of a running status. Thus only one
     * call can notify.
     *
     * Do not change this to $run->update([...]). Then the claim is not safe
     * for parallel calls, and the event above fires two times.
     */
    private function claim(int $runId): bool
    {
        return BackgroundJobs::table()
            ->where('id', $runId)
            ->whereIn('status', array_map(fn (BackgroundJobStatus $status) => $status->value, BackgroundJobStatus::running()))
            // The seal guard. A run must not settle before the dispatcher
            // declares the number of children. Otherwise, on a sync queue,
            // the first child settles the run at 1 of 1 before the other
            // children exist.
            ->whereNotNull('children_expected')
            ->whereRaw('(children_succeeded + children_failed) >= children_expected')
            ->update([
                // The database uses the values of the row before the update.
                'status' => BackgroundJobs::table()->raw(sprintf(
                    'CASE WHEN children_expected > 0 AND children_succeeded = 0 THEN %d
                          WHEN children_failed > 0 THEN %d
                          ELSE %d END',
                    BackgroundJobStatus::Failed->value,
                    BackgroundJobStatus::CompletedWithErrors->value,
                    BackgroundJobStatus::Finished->value,
                )),
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }
}

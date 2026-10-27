<?php

namespace Bernskiold\LaravelBackgroundJobs;

use Bernskiold\LaravelBackgroundJobs\Actions\SettleBackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Exceptions\SealedBackgroundJobRunException;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts the jobs of a bulk operation under one parent job. The operation then
 * reports one outcome, and sends one notification, and not one for each item.
 *
 * Always use these three steps. The seal is necessary:
 *
 *     $run = BackgroundJobRun::start($type, jobable: $study);
 *     foreach ($items as $item) { $run->addChild($item); ... }
 *     $run->seal();
 *
 * The run cannot settle before seal() declares the number of children. This
 * stops the first child on a sync queue from settling the run at 1 of 1
 * before the other children exist. A run that you do not seal runs forever.
 * The reconcile command reports it.
 */
class BackgroundJobRun
{
    private function __construct(public readonly BackgroundJob $job) {}

    /**
     * Set $notify to false to keep the owner but send no notification when
     * the run settles.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function start(
        BackgroundJobType $type,
        ?Model $jobable = null,
        array $meta = [],
        int|string|null $userId = null,
        bool $withoutOwner = false,
        bool $notify = true,
    ): self {
        $job = BackgroundJobs::model()::generateFor(
            model: $jobable,
            type: $type,
            meta: $meta,
            userId: $userId,
            withoutOwner: $withoutOwner,
            notify: $notify,
        );

        return new self($job->start());
    }

    public static function for(BackgroundJob $job): self
    {
        return new self($job);
    }

    /**
     * Make a child job of the same type as the run.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws SealedBackgroundJobRunException
     */
    public function addChild(?Model $jobable, array $meta = []): BackgroundJob
    {
        if ($this->job->fresh()?->isSealed()) {
            throw SealedBackgroundJobRunException::for($this->job);
        }

        return BackgroundJobs::model()::generateFor(
            model: $jobable,
            type: $this->job->type,
            meta: $meta,
            parent: $this->job,
        );
    }

    /**
     * Record work that you did not dispatch on purpose.
     *
     * A skipped item is not a failed item, because a skipped item never ran.
     * The run reports skipped items separately, and they are not part of the
     * expected children.
     *
     * @param  array<string, mixed>  $context
     */
    public function noteSkipped(string $reason, array $context = []): void
    {
        $this->job->appendMetaArrayItem('skipped', $context + ['reason' => $reason]);
        $this->job->logChannel()->info("Skipped: {$reason}", $context);
    }

    /**
     * Set the number of expected children, and try to settle the run.
     *
     * The settle here covers two cases that the roll-up listener cannot. All
     * the children can be finished before you seal the run. And a run can have
     * no children at all, because all items were skipped. That run must also
     * send one correct notification.
     *
     * Only give $expected when children are made later in other processes, so
     * that you cannot count them here.
     */
    public function seal(?int $expected = null): BackgroundJob
    {
        $expected ??= $this->job->children()->count();

        BackgroundJobs::table()
            ->where('id', $this->job->getKey())
            ->whereNull('children_expected')
            ->update([
                'children_expected' => $expected,
                'sealed_at' => now(),
                'updated_at' => now(),
            ]);

        app(SettleBackgroundJobRun::class)->execute($this->job->getKey());

        return $this->job->refresh();
    }
}

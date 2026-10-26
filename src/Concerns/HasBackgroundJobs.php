<?php

namespace Bernskiold\LaravelBackgroundJobs\Concerns;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Use this trait on a model that background jobs can run for.
 */
trait HasBackgroundJobs
{
    /**
     * @return MorphMany<BackgroundJob, $this>
     */
    public function backgroundJobs(): MorphMany
    {
        return $this->morphMany(BackgroundJobs::model(), 'jobable');
    }

    /**
     * Tells if a job of the type is waiting or processing for this model. A
     * stale job does not count.
     */
    public function hasUnfinishedBackgroundJob(BackgroundJobType $type): bool
    {
        return $this->backgroundJobs()
            ->ofType($type)
            ->notFinished()
            ->notStale()
            ->exists();
    }

    /**
     * The keys of the models of this class that have a job of the type that is
     * waiting or processing. A stale job does not count. Use it to stop early
     * when nothing runs.
     *
     * @return Collection<int, int|string>
     */
    public static function idsWithUnfinishedBackgroundJob(BackgroundJobType $type): Collection
    {
        return BackgroundJobs::model()::query()
            ->where('jobable_type', (new static)->getMorphClass())
            ->ofType($type)
            ->notFinished()
            ->notStale()
            ->pluck('jobable_id');
    }
}

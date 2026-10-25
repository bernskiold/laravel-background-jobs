<?php

namespace Bernskiold\LaravelBackgroundJobs\Livewire;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

/**
 * The behavior of the list of the background jobs of one model. Extend it and
 * add a render() method with your own view.
 *
 * The list includes the children of runs. A run for many models has no model
 * of its own. Thus a list of roots only is empty for the bulk operations that
 * this list must show.
 *
 * The children of a run broadcast on the channel of their run. This component
 * cannot know these channels before it renders. Thus the view must poll while
 * $this->hasRunningJobs is true.
 *
 * @property-read LengthAwarePaginator<int, BackgroundJob> $jobs
 * @property-read bool $hasRunningJobs
 */
abstract class ModelBackgroundJobs extends Component
{
    use WithoutUrlPagination,
        WithPagination;

    #[Locked]
    public string $modelType;

    #[Locked]
    public int|string $modelId;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.BackgroundJobChangedStatus::listChannel().',.'.BackgroundJobChangedStatus::BROADCAST_NAME => '$refresh',
        ];
    }

    #[Computed]
    public function hasRunningJobs(): bool
    {
        return collect($this->jobs()->items())->contains(fn (BackgroundJob $job) => $job->status->isRunning());
    }

    /**
     * @return LengthAwarePaginator<int, BackgroundJob>
     */
    #[Computed]
    public function jobs(): LengthAwarePaginator
    {
        return $this->query()->paginate($this->perPage(), $this->columns());
    }

    /**
     * @return Builder<BackgroundJob>
     */
    protected function query(): Builder
    {
        return BackgroundJobs::model()::query()
            ->forModel($this->modelId, $this->modelType)
            ->with(['user', 'parent'])
            ->latest()
            ->latest('id');
    }

    /**
     * @return list<string>
     */
    protected function columns(): array
    {
        return [
            'id',
            'parent_id',
            'user_id',
            'jobable_id',
            'jobable_type',
            'type',
            'status',
            'failure_reason',
            'progress_total',
            'progress_processed',
            'finished_at',
            'created_at',
        ];
    }

    protected function perPage(): int
    {
        return 10;
    }
}

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
 * The behavior of the list of the jobs in a run. Extend it and add a render()
 * method with your own view.
 *
 * The view can use $this->run, $this->jobs, $statusFilter and statuses().
 *
 * @property-read BackgroundJob $run
 * @property-read LengthAwarePaginator<int, BackgroundJob> $jobs
 */
abstract class BackgroundJobRunChildren extends Component
{
    use WithoutUrlPagination,
        WithPagination;

    #[Locked]
    public int $runId;

    /**
     * When some jobs of a large run fail, the user must find them. The filter
     * shows them with one click.
     */
    public string $statusFilter = 'all';

    public function mount(int $runId): void
    {
        $this->runId = $runId;

        $this->authorize($this->run->viewAbility(), $this->run);
    }

    /**
     * The children broadcast on the channel of their run, and not on the
     * shared channel.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.BackgroundJobChangedStatus::runChannel($this->runId).',.'.BackgroundJobChangedStatus::BROADCAST_NAME => '$refresh',
        ];
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function run(): BackgroundJob
    {
        return BackgroundJobs::model()::query()->findOrFail($this->runId);
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
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return [
            'all' => __('All'),
            'failed' => __('Failed'),
            'finished' => __('Finished'),
            'running' => __('Running'),
        ];
    }

    /**
     * @return Builder<BackgroundJob>
     */
    protected function query(): Builder
    {
        return BackgroundJobs::model()::query()
            ->forParents($this->runId)
            ->when($this->statusFilter === 'failed', fn (Builder $query) => $query->failed())
            ->when($this->statusFilter === 'finished', fn (Builder $query) => $query->finished())
            ->when($this->statusFilter === 'running', fn (Builder $query) => $query->notFinished())
            ->with(['jobable', 'user'])
            ->orderBy('id');
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
            'meta',
            'finished_at',
            'created_at',
        ];
    }

    protected function perPage(): int
    {
        return 25;
    }
}

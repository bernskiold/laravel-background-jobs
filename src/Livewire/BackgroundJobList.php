<?php

namespace Bernskiold\LaravelBackgroundJobs\Livewire;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The behavior of a page that lists background jobs. Extend it and add a
 * render() method with your own view.
 *
 * The view can use $this->jobs, $this->canViewAllJobs and $includeChildren.
 *
 * @property-read LengthAwarePaginator<int, BackgroundJob> $jobs
 * @property-read bool $canViewAllJobs
 */
abstract class BackgroundJobList extends Component
{
    use WithPagination;

    /**
     * The list does not show the children of a run by default. One bulk
     * operation can add hundreds of children. The run links to its children.
     */
    #[Url]
    public bool $includeChildren = false;

    public function mount(): void
    {
        $this->authorize($this->viewAnyAbility(), BackgroundJobs::model());
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.BackgroundJobChangedStatus::listChannel().',.'.BackgroundJobChangedStatus::BROADCAST_NAME => '$refresh',
        ];
    }

    public function updatedIncludeChildren(): void
    {
        $this->resetPage();
    }

    /**
     * A user who can see all jobs also sees the jobs of other users and the
     * system jobs. Other users only see their own jobs.
     */
    #[Computed]
    public function canViewAllJobs(): bool
    {
        return Gate::allows($this->viewAllAbility(), BackgroundJobs::model());
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
     * Override this method to filter or sort the list. Call the parent method
     * first.
     *
     * @return Builder<BackgroundJob>
     */
    protected function query(): Builder
    {
        return BackgroundJobs::model()::query()
            ->unless($this->canViewAllJobs(), fn (Builder $query) => $query->mine())
            ->unless($this->includeChildren, fn (Builder $query) => $query->roots())
            ->with('user')
            ->latest()
            ->latest('id');
    }

    /**
     * The list does not load the meta, which can be large for a run.
     *
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
            'children_expected',
            'children_succeeded',
            'children_failed',
            'finished_at',
            'created_at',
        ];
    }

    protected function perPage(): int
    {
        return 25;
    }

    protected function viewAnyAbility(): string
    {
        return 'viewAny';
    }

    protected function viewAllAbility(): string
    {
        return 'viewAllJobs';
    }
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Livewire;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Events\BackgroundJobChangedStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Livewire\Component;

/**
 * The behavior of the page of one background job. Extend it and add a
 * render() method with your own view.
 *
 * The view can use $backgroundJob, $logFilter, logLevels() and logContents().
 */
abstract class BackgroundJobDetail extends Component
{
    public BackgroundJob $backgroundJob;

    /**
     * The log level that the log shows. The value "all" shows all levels.
     */
    public string $logFilter = 'all';

    public function mount(int|string $jobId): void
    {
        $this->backgroundJob = BackgroundJobs::model()::query()->findOrFail($jobId);

        $this->authorize($this->backgroundJob->viewAbility(), $this->backgroundJob);
    }

    /**
     * The page refreshes when the job changes status. The counters of a run
     * change when its children settle, and the children broadcast on the
     * channel of the run.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $event = ',.'.BackgroundJobChangedStatus::BROADCAST_NAME;

        $listeners = [
            'echo-private:'.BackgroundJobChangedStatus::jobChannel($this->backgroundJob->getKey()).$event => '$refresh',
        ];

        if ($this->backgroundJob->isRun()) {
            $listeners['echo-private:'.BackgroundJobChangedStatus::runChannel($this->backgroundJob->getKey()).$event] = '$refresh';
        }

        return $listeners;
    }

    public function markAsFailed(): void
    {
        $this->authorize($this->backgroundJob->updateAbility(), $this->backgroundJob);

        $this->backgroundJob->fail(__('Manually failed by :name', [
            'name' => auth()->user()?->getAttribute('name') ?? __('a user'),
        ]));

        $this->markedAsFailed();
    }

    /**
     * Override this method to show a message after a user marks the job as
     * failed.
     */
    protected function markedAsFailed(): void {}

    /**
     * @return array<string, string>
     */
    public function logLevels(): array
    {
        return [
            'all' => __('All'),
            'error' => __('Error'),
            'warning' => __('Warning'),
            'info' => __('Info'),
            'debug' => __('Debug'),
        ];
    }

    public function logContents(): string
    {
        $level = array_key_exists($this->logFilter, $this->logLevels()) ? $this->logFilter : 'all';

        return $this->backgroundJob->getLogContentsForLevel($level);
    }
}

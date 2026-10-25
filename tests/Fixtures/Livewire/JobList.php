<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire;

use Bernskiold\LaravelBackgroundJobs\Livewire\BackgroundJobList;

class JobList extends BackgroundJobList
{
    public function render(): string
    {
        return <<<'BLADE'
        <div>
            @foreach($this->jobs as $job)
                <span>[job-{{ $job->id }}]</span>
            @endforeach
        </div>
        BLADE;
    }
}

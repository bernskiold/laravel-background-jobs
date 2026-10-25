<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire;

use Bernskiold\LaravelBackgroundJobs\Livewire\BackgroundJobRunChildren;

class RunChildren extends BackgroundJobRunChildren
{
    public function render(): string
    {
        return <<<'BLADE'
        <div>
            @foreach($this->jobs as $job)
                <span>{{ $job->label() }}</span>
            @endforeach
        </div>
        BLADE;
    }
}

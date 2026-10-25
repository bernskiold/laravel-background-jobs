<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire;

use Bernskiold\LaravelBackgroundJobs\Livewire\ModelBackgroundJobs;

class ReportJobs extends ModelBackgroundJobs
{
    public function render(): string
    {
        return <<<'BLADE'
        <div>
            @foreach($this->jobs as $job)
                <span>{{ $job->typeLabel() }} #{{ $job->id }}</span>
            @endforeach
        </div>
        BLADE;
    }
}

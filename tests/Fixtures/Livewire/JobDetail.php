<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Livewire;

use Bernskiold\LaravelBackgroundJobs\Livewire\BackgroundJobDetail;

class JobDetail extends BackgroundJobDetail
{
    public bool $notified = false;

    protected function markedAsFailed(): void
    {
        $this->notified = true;
    }

    public function render(): string
    {
        return <<<'BLADE'
        <div>
            <h1>{{ $backgroundJob->typeLabel() }}</h1>
            <pre>{{ $this->logContents() }}</pre>
        </div>
        BLADE;
    }
}

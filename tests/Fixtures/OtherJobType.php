<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures;

use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;

enum OtherJobType: string implements BackgroundJobType
{
    case SendNewsletter = 'send_newsletter';

    public function label(): string
    {
        return 'Send Newsletter';
    }
}

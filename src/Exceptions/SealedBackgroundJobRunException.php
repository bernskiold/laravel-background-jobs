<?php

namespace Bernskiold\LaravelBackgroundJobs\Exceptions;

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use LogicException;

class SealedBackgroundJobRunException extends LogicException
{
    public static function for(BackgroundJob $run): self
    {
        return new self(
            "Background job run #{$run->getKey()} is sealed. You cannot add more children to it. ".
            'Create all the children before you call seal().'
        );
    }
}

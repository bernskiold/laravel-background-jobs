<?php

namespace Bernskiold\LaravelBackgroundJobs\Exceptions;

use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;
use InvalidArgumentException;

class InvalidBackgroundJobType extends InvalidArgumentException
{
    public static function notATypeEnum(string $class): self
    {
        return new self(sprintf(
            'The class [%s] is not a background job type. Use a backed enum that implements [%s].',
            $class,
            BackgroundJobType::class,
        ));
    }
}

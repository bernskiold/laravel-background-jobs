<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures;

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;

class CustomBackgroundJob extends BackgroundJob
{
    public static int $bootedCalls = 0;

    protected static function booted(): void
    {
        static::$bootedCalls++;
    }

    public function url(): string
    {
        return "https://example.com/custom-jobs/{$this->getKey()}";
    }
}

<?php

namespace Bernskiold\LaravelBackgroundJobs\Contracts;

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;

/**
 * A queued job that does the work of a background job.
 */
interface HasBackgroundJob
{
    public function backgroundJob(): ?BackgroundJob;
}
